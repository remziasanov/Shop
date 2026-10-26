<?php

declare(strict_types=1);

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

dataset('staff', [
    'admin' => fn () => User::factory()->admin()->create(),
    'manager' => fn () => User::factory()->manager()->create(),
]);

it('renders list, create and edit pages for admin and manager', function (User $user) {
    $category = Category::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListCategories::class)->assertSuccessful()->assertCanSeeTableRecords([$category]);
    Livewire::test(CreateCategory::class)->assertSuccessful();
    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->assertSuccessful();
})->with('staff');

it('creates a category with a parent and sets path and depth', function () {
    $this->actingAs(User::factory()->manager()->create());
    $parent = Category::factory()->create(['name' => 'Мебель']);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Кресла', 'parent_id' => $parent->id, 'is_active' => true, 'sort_order' => 0])
        ->call('create')
        ->assertHasNoFormErrors();

    $child = Category::where('name', 'Кресла')->firstOrFail();

    expect($child->depth)->toBe(2)
        ->and($child->path)->toBe($parent->id.'/'.$child->id.'/')
        ->and($child->slug)->toBe('kresla');
});

it('rejects a descendant as the parent', function () {
    $this->actingAs(User::factory()->admin()->create());
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    Livewire::test(EditCategory::class, ['record' => $root->getRouteKey()])
        ->fillForm(['parent_id' => $child->id])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($root->fresh()->parent_id)->toBeNull();
});

it('shows a tree error as a validation error instead of failing', function () {
    $this->actingAs(User::factory()->admin()->create());
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    // Bypass the options check: the observer must still be mapped to a form error.
    Livewire::test(EditCategory::class, ['record' => $root->getRouteKey()])
        ->set('data.parent_id', $child->id)
        ->call('save')
        ->assertHasErrors();

    expect($root->fresh()->parent_id)->toBeNull();
});

it('deletes a category moving products and subcategories to the parent', function () {
    $this->actingAs(User::factory()->admin()->create());
    $root = Category::factory()->create();
    $middle = Category::factory()->childOf($root)->create();
    $leaf = Category::factory()->childOf($middle)->create();
    $product = Product::factory()->create(['category_id' => $middle->id]);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($middle));

    expect(Category::find($middle->id))->toBeNull()
        ->and($product->fresh()->category_id)->toBe($root->id)
        ->and($leaf->fresh()->parent_id)->toBe($root->id)
        ->and($leaf->fresh()->depth)->toBe(2);
});

it('refuses to delete a root category that holds products', function () {
    $this->actingAs(User::factory()->admin()->create());
    $root = Category::factory()->create();
    Product::factory()->create(['category_id' => $root->id]);

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($root));

    expect(Category::find($root->id))->not->toBeNull();
});

it('deletes an empty category normally', function () {
    $this->actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create();

    Livewire::test(ListCategories::class)
        ->callAction(TestAction::make('delete')->table($category));

    expect(Category::find($category->id))->toBeNull();
});

it('bulk deletes only empty categories', function () {
    $this->actingAs(User::factory()->admin()->create());
    $empty = Category::factory()->create();
    $full = Category::factory()->create();
    Product::factory()->create(['category_id' => $full->id]);

    Livewire::test(ListCategories::class)
        ->selectTableRecords([$empty->id, $full->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Category::find($empty->id))->toBeNull()
        ->and(Category::find($full->id))->not->toBeNull();
});

it('counts products including subcategories', function () {
    $this->actingAs(User::factory()->admin()->create());
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    Product::factory()->count(2)->create(['category_id' => $root->id]);
    Product::factory()->count(3)->create(['category_id' => $child->id]);

    Livewire::test(ListCategories::class)
        ->assertTableColumnStateSet('products_count', 5, $root)
        ->assertTableColumnStateSet('products_count', 3, $child);
});

it('lists children right after their parent', function () {
    $this->actingAs(User::factory()->admin()->create());
    $a = Category::factory()->create(['name' => 'A', 'sort_order' => 1]);
    $b = Category::factory()->create(['name' => 'B', 'sort_order' => 2]);
    $a1 = Category::factory()->childOf($a)->create(['name' => 'A1']);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$a, $a1, $b], inOrder: true);
});

it('reorders siblings once a parent is selected', function () {
    $this->actingAs(User::factory()->admin()->create());
    $parent = Category::factory()->create();
    $x = Category::factory()->childOf($parent)->create(['sort_order' => 0]);
    $y = Category::factory()->childOf($parent)->create(['sort_order' => 1]);

    Livewire::test(ListCategories::class)
        ->filterTable('parent', ['parent' => $parent->id])
        ->call('reorderTable', [$y->id, $x->id]);

    expect($y->fresh()->sort_order)->toBeLessThan($x->fresh()->sort_order)
        ->and(app(CategoryTree::class)->node($parent->id)->children[0]->id)->toBe($y->id);
});

it('does not allow reordering without a parent filter', function () {
    $this->actingAs(User::factory()->admin()->create());
    $x = Category::factory()->create(['sort_order' => 0]);
    $y = Category::factory()->create(['sort_order' => 1]);

    Livewire::test(ListCategories::class)->call('reorderTable', [$y->id, $x->id]);

    expect($y->fresh()->sort_order)->toBe(1);
});

it('sanitizes the description on save', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name' => 'Свет',
            'description' => '<p>Текст</p><script>alert(1)</script>',
            'is_active' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $description = (string) Category::where('name', 'Свет')->value('description');

    expect($description)->toContain('Текст')->not->toContain('script')->not->toContain('alert');
});
