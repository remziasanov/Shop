<?php

declare(strict_types=1);

use App\Exceptions\CategoryTreeException;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tree = app(CategoryTree::class);
});

/** Build a chain root > c2 > c3 > c4 and return the categories. */
function makeChain(int $levels = 4): array
{
    $chain = [];
    $parent = null;

    for ($i = 1; $i <= $levels; $i++) {
        $parent = Category::factory()->create(['name' => "Уровень $i", 'parent_id' => $parent?->id]);
        $chain[] = $parent;
    }

    return $chain;
}

it('sets path and depth on create', function (): void {
    [$root, $child, $grand] = makeChain(3);

    expect($root->path)->toBe($root->id.'/')->and($root->depth)->toBe(1)
        ->and($child->path)->toBe($root->id.'/'.$child->id.'/')->and($child->depth)->toBe(2)
        ->and($grand->fresh()->path)->toBe("{$root->id}/{$child->id}/{$grand->id}/")->and($grand->fresh()->depth)->toBe(3);
});

it('recomputes path and depth of all descendants when the parent changes', function (): void {
    $a = Category::factory()->create();
    $b = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $a->id]);
    $grand = Category::factory()->create(['parent_id' => $child->id]);

    $child->update(['parent_id' => $b->id]);

    expect($child->fresh()->path)->toBe("{$b->id}/{$child->id}/")
        ->and($grand->fresh()->path)->toBe("{$b->id}/{$child->id}/{$grand->id}/")
        ->and($grand->fresh()->depth)->toBe(3);

    $child->update(['parent_id' => null]);

    expect($child->fresh()->depth)->toBe(1)
        ->and($grand->fresh()->path)->toBe("{$child->id}/{$grand->id}/")
        ->and($grand->fresh()->depth)->toBe(2);
});

it('rejects the category itself as parent (AD-32)', function (): void {
    $category = Category::factory()->create();

    expect(fn () => $category->update(['parent_id' => $category->id]))->toThrow(CategoryTreeException::class, __('catalog.errors.parent_is_self'));
});

it('rejects a descendant as parent (AD-32)', function (): void {
    [$root, $child, $grand] = makeChain(3);

    expect(fn () => $root->update(['parent_id' => $grand->id]))->toThrow(CategoryTreeException::class, __('catalog.errors.parent_is_descendant'))
        ->and($root->fresh()->parent_id)->toBeNull();
});

it('rejects a missing parent', function (): void {
    expect(fn () => Category::factory()->create(['parent_id' => 99999]))->toThrow(CategoryTreeException::class);
});

it('allows four levels but rejects a fifth (AD-30)', function (): void {
    $chain = makeChain(4);

    expect($chain[3]->depth)->toBe(4)
        ->and(fn () => Category::factory()->create(['parent_id' => $chain[3]->id]))->toThrow(CategoryTreeException::class);
});

it('rejects a move that pushes a subtree deeper than four levels', function (): void {
    $chain = makeChain(3);
    $other = Category::factory()->create();
    $deep = Category::factory()->create(['parent_id' => $other->id]);
    $deeper = Category::factory()->create(['parent_id' => $deep->id]);

    // root(3 levels) moved under $deeper (depth 3) would reach depth 6.
    expect(fn () => $chain[0]->update(['parent_id' => $deeper->id]))->toThrow(CategoryTreeException::class);
    // A single leaf fits under depth 3.
    $leaf = Category::factory()->create();
    $leaf->update(['parent_id' => $deeper->id]);
    expect($leaf->fresh()->depth)->toBe(4);
});

it('offers only valid parents in the form options', function (): void {
    [$root, $child, $grand, $leaf] = makeChain(4);
    $solo = Category::factory()->create(['name' => 'Одиночка']);

    $forNew = $this->tree->parentOptions();
    expect(array_keys($forNew))->toBe([$solo->id, $root->id, $child->id, $grand->id]);

    $forChild = $this->tree->parentOptions($child);
    // Not itself, not its descendants; the subtree is 3 levels high so only roots fit.
    expect(array_keys($forChild))->toBe([$solo->id, $root->id]);

    expect($forNew[$grand->id])->toBe('Уровень 1 / Уровень 2 / Уровень 3');
});

it('builds indented options', function (): void {
    [$root, $child] = makeChain(2);

    expect($this->tree->indentedOptions())->toBe([$root->id => 'Уровень 1', $child->id => '— Уровень 2'])
        ->and($this->tree->options($child->id))->toBe([$root->id => 'Уровень 1']);
});

it('moves children and products to the parent on delete (AD-33)', function (): void {
    [$root, $middle, $leaf] = makeChain(3);
    $product = Product::factory()->inCategory($middle)->create();
    $trashed = Product::factory()->inCategory($middle)->create();
    $trashed->delete();

    $this->tree->deleteMovingToParent($middle);

    expect(Category::find($middle->id))->toBeNull()
        ->and($leaf->fresh()->parent_id)->toBe($root->id)
        ->and($leaf->fresh()->path)->toBe("{$root->id}/{$leaf->id}/")
        ->and($leaf->fresh()->depth)->toBe(2)
        ->and($product->fresh()->category_id)->toBe($root->id)
        ->and(Product::withTrashed()->find($trashed->id)->category_id)->toBe($root->id);
});

it('refuses to delete a root with products by moving, and a plain delete of a non-empty category', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $root->id]);
    Product::factory()->inCategory($root)->create();

    expect(fn () => $this->tree->deleteMovingToParent($root))->toThrow(CategoryTreeException::class)
        ->and(Category::find($root->id))->not->toBeNull()
        ->and(fn () => $root->delete())->toThrow(CategoryTreeException::class)
        ->and(fn () => $child->parent->delete())->toThrow(CategoryTreeException::class);
});

it('promotes children to roots when a root without products is deleted', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $root->id]);
    $grand = Category::factory()->create(['parent_id' => $child->id]);

    $this->tree->deleteMovingToParent($root);

    expect($child->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->depth)->toBe(1)
        ->and($grand->fresh()->path)->toBe("{$child->id}/{$grand->id}/");
});

it('deletes an empty category with a plain delete', function (): void {
    $category = Category::factory()->create();
    $category->delete();

    expect(Category::count())->toBe(0);
});

it('caches the tree and flushes it on any category change (NF-04)', function (): void {
    $root = Category::factory()->create(['name' => 'Корень']);

    expect($this->tree->roots())->toHaveCount(1)->and(Cache::has(CategoryTree::CACHE_KEY))->toBeTrue();

    DB::enableQueryLog();
    $this->tree->roots();
    $this->tree->descendantIds($root->id);
    $this->tree->visibleCategoryIds();
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();

    $child = Category::factory()->create(['parent_id' => $root->id]);
    expect(Cache::has(CategoryTree::CACHE_KEY))->toBeFalse()
        ->and($this->tree->node($root->id)->children)->toHaveCount(1);

    $child->update(['name' => 'Новое имя']);
    expect($this->tree->node($child->id)->name)->toBe('Новое имя');

    $child->delete();
    expect($this->tree->node($child->id))->toBeNull();
});

it('lists descendants including self, breadcrumbs and ancestors', function (): void {
    [$root, $child, $grand] = makeChain(3);
    $other = Category::factory()->create();

    expect($this->tree->descendantIds($root->id))->toEqualCanonicalizing([$root->id, $child->id, $grand->id])
        ->and($this->tree->descendantIds($other->id))->toBe([$other->id])
        ->and(array_map(fn ($n) => $n->id, $this->tree->breadcrumbs($grand)))->toBe([$root->id, $child->id, $grand->id])
        ->and(array_map(fn ($n) => $n->id, $this->tree->ancestors($grand->id)))->toBe([$root->id, $child->id])
        ->and($grand->fresh()->ancestors()->pluck('id')->all())->toBe([$root->id, $child->id])
        ->and($root->descendants()->pluck('id')->all())->toEqualCanonicalizing([$child->id, $grand->id]);
});

it('counts products with descendants and ignores soft-deleted ones (D-021)', function (): void {
    [$root, $child, $grand] = makeChain(3);
    $other = Category::factory()->create();
    Product::factory()->count(2)->inCategory($root)->create();
    Product::factory()->count(3)->inCategory($child)->create();
    Product::factory()->inCategory($grand)->create();
    Product::factory()->inCategory($grand)->create()->delete();
    Product::factory()->inCategory($grand)->inactive()->create();

    $counts = $this->tree->productCountsWithDescendants();

    expect($counts[$root->id])->toBe(2 + 3 + 2)
        ->and($counts[$child->id])->toBe(5)
        ->and($counts[$grand->id])->toBe(2)
        ->and($counts[$other->id])->toBe(0);

    $active = $this->tree->productCountsWithDescendants(storefront: true);
    expect($active[$root->id])->toBe(6)->and($active[$grand->id])->toBe(1);
});

it('counts products in a single grouped query', function (): void {
    makeChain(3);
    Product::factory()->count(3)->create();

    DB::enableQueryLog();
    $this->tree->productCountsWithDescendants();
    $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'products'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

it('storefront counts skip products under hidden categories', function (): void {
    [$root, $child] = makeChain(2);
    Product::factory()->inCategory($child)->create();
    $child->update(['is_active' => false]);

    $counts = $this->tree->productCountsWithDescendants(storefront: true);

    expect($counts[$root->id])->toBe(0)->and($counts[$child->id])->toBe(0);
});

it('hides the subtree of an inactive ancestor (SF-16)', function (): void {
    [$root, $child, $grand] = makeChain(3);
    $sibling = Category::factory()->create();

    expect($this->tree->visibleCategoryIds())->toEqualCanonicalizing([$root->id, $child->id, $grand->id, $sibling->id]);

    $child->update(['is_active' => false]);
    expect($this->tree->visibleCategoryIds())->toEqualCanonicalizing([$root->id, $sibling->id])
        ->and($this->tree->isVisible($grand->id))->toBeFalse()
        ->and(Category::visible()->pluck('id')->all())->toEqualCanonicalizing([$root->id, $sibling->id])
        ->and(Category::active()->pluck('id')->all())->toEqualCanonicalizing([$root->id, $grand->id, $sibling->id]);
});

it('reorders siblings', function (): void {
    $root = Category::factory()->create();
    $a = Category::factory()->create(['parent_id' => $root->id, 'name' => 'Аа']);
    $b = Category::factory()->create(['parent_id' => $root->id, 'name' => 'Бб']);
    $c = Category::factory()->create(['parent_id' => $root->id, 'name' => 'Вв']);

    $this->tree->reorder($root->id, [$c->id, $a->id, $b->id]);

    expect(array_map(fn ($n) => $n->id, $this->tree->node($root->id)->children))->toBe([$c->id, $a->id, $b->id])
        ->and($root->children()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

it('reloads from the cache after the in-memory copy is forgotten', function (): void {
    $root = Category::factory()->create(['name' => 'Корень']);
    expect($this->tree->node($root->id)->name)->toBe('Корень');

    // A change made by another process: the cache is rebuilt there, this instance keeps its memo.
    DB::table('categories')->where('id', $root->id)->update(['name' => 'Другое']);
    Cache::forget(CategoryTree::CACHE_KEY);
    expect($this->tree->node($root->id)->name)->toBe('Корень');

    $this->tree->forgetMemo();
    expect($this->tree->node($root->id)->name)->toBe('Другое');
});

it('forgets the memo when a queue worker loops', function (): void {
    $root = Category::factory()->create(['name' => 'Корень']);
    $tree = app(CategoryTree::class);
    $tree->node($root->id);

    DB::table('categories')->where('id', $root->id)->update(['name' => 'Новое']);
    Cache::forget(CategoryTree::CACHE_KEY);

    event(new Looping('database', 'default'));

    expect($tree->node($root->id)->name)->toBe('Новое');
});
