<?php

declare(strict_types=1);

use App\Enums\FilterSource;
use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryFilterService;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\DB;

/**
 * FL-05, FL-06: the effective filter set of a category with inheritance and the limit.
 */
function attrFilters(): CategoryFilterService
{
    return app(CategoryFilterService::class);
}

function attrSetRows(Category $category, array $attributes): void
{
    foreach (array_values($attributes) as $position => $attribute) {
        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $attribute->id, 'sort_order' => ($position + 1) * 10]);
    }
}

describe('inheritance', function (): void {
    it('returns the own set in order', function (): void {
        $category = Category::factory()->create();
        [$a, $b] = [Attribute::factory()->create(), Attribute::factory()->create()];
        attrSetRows($category, [$b, $a]);

        $set = attrFilters()->resolve($category->id);

        expect($set->rows->pluck('attribute_id')->all())->toBe([$b->id, $a->id])
            ->and($set->sourceCategoryId)->toBe($category->id)
            ->and($set->isInherited())->toBeFalse();
    });

    it('takes the set of the nearest ancestor with own rows and never merges sets', function (): void {
        $root = Category::factory()->create();
        $middle = Category::factory()->childOf($root)->create();
        $leaf = Category::factory()->childOf($middle)->create();
        $other = Category::factory()->create();
        [$a, $b, $c] = [Attribute::factory()->create(), Attribute::factory()->create(), Attribute::factory()->create()];
        attrSetRows($root, [$a]);
        attrSetRows($middle, [$b, $c]);

        $leafSet = attrFilters()->resolve($leaf->id);
        $middleSet = attrFilters()->resolve($middle->id);

        expect($leafSet->rows->pluck('attribute_id')->all())->toBe([$b->id, $c->id])
            ->and($leafSet->sourceCategoryId)->toBe($middle->id)
            ->and($leafSet->isInherited())->toBeTrue()
            ->and($middleSet->isInherited())->toBeFalse()
            ->and(attrFilters()->resolve($root->id)->rows->pluck('attribute_id')->all())->toBe([$a->id])
            ->and(attrFilters()->resolve($other->id)->isEmpty())->toBeTrue()
            ->and(attrFilters()->resolve($other->id)->sourceCategoryId)->toBeNull();
    });

    it('inherits from the root when the middle category has no rows', function (): void {
        $root = Category::factory()->create();
        $middle = Category::factory()->childOf($root)->create();
        $leaf = Category::factory()->childOf($middle)->create();
        attrSetRows($root, [Attribute::factory()->create()]);

        expect(attrFilters()->resolve($leaf->id)->sourceCategoryId)->toBe($root->id);
    });

    it('reads the whole chain with one query regardless of depth', function (): void {
        $root = Category::factory()->create();
        $middle = Category::factory()->childOf($root)->create();
        $leaf = Category::factory()->childOf($middle)->create();
        attrSetRows($root, [Attribute::factory()->create()]);
        // Warm the cached tree, then count.
        attrFilters()->resolve($leaf->id);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app()->forgetInstance(CategoryFilterService::class);
        attrFilters()->resolve($leaf->id);

        // One query for the rows, one for the eager-loaded attributes.
        expect($queries)->toHaveCount(2);
    });
});

describe('limit and usable rows', function (): void {
    it('keeps at most filters_max_per_category rows (default 9)', function (): void {
        $category = Category::factory()->create();
        attrSetRows($category, Attribute::factory()->count(11)->create()->all());

        expect(attrFilters()->resolve($category->id)->rows)->toHaveCount(9);

        $settings = app(CatalogSettings::class);
        $settings->filters_max_per_category = 3;
        $settings->save();
        app()->forgetInstance(CategoryFilterService::class);

        expect(attrFilters()->resolve($category->id)->rows)->toHaveCount(3);
    });

    it('skips rows of inactive or non-filterable attributes before applying the limit', function (): void {
        $category = Category::factory()->create();
        $good = Attribute::factory()->create();
        attrSetRows($category, [Attribute::factory()->inactive()->create(), Attribute::factory()->notFilterable()->create(), $good]);

        expect(attrFilters()->resolve($category->id)->rows->pluck('attribute_id')->all())->toBe([$good->id]);
    });

    it('does not fall through to the parent when the own set has only unusable rows', function (): void {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        attrSetRows($root, [Attribute::factory()->create()]);
        attrSetRows($child, [Attribute::factory()->inactive()->create()]);

        $set = attrFilters()->resolve($child->id);

        expect($set->sourceCategoryId)->toBe($child->id)->and($set->isEmpty())->toBeTrue();
    });

    it('keeps option rows (stage 11)', function (): void {
        $category = Category::factory()->create();
        CategoryFilter::factory()->create(['category_id' => $category->id, 'source' => FilterSource::Option, 'attribute_id' => null, 'option_type_id' => 7]);

        $row = attrFilters()->resolve($category->id)->rows->first();

        expect($row->source)->toBe(FilterSource::Option)->and($row->option_type_id)->toBe(7);
    });
});

describe('writing sets', function (): void {
    it('replaces the own set, numbering the order, and bumps the catalog version', function (): void {
        $category = Category::factory()->create();
        [$a, $b] = [Attribute::factory()->create(), Attribute::factory()->create()];
        attrSetRows($category, [$a]);
        $before = CatalogVersion::current();

        attrFilters()->replaceSet($category, [['attribute_id' => $b->id, 'is_expanded' => true], ['attribute_id' => $a->id]]);

        $rows = CategoryFilter::query()->where('category_id', $category->id)->orderBy('sort_order')->get();

        expect($rows->pluck('attribute_id')->all())->toBe([$b->id, $a->id])
            ->and($rows[0]->is_expanded)->toBeTrue()
            ->and($rows[1]->is_expanded)->toBeFalse()
            ->and(CatalogVersion::current())->not->toBe($before)
            ->and(attrFilters()->resolve($category->id)->rows->pluck('attribute_id')->all())->toBe([$b->id, $a->id]);
    });

    it('refuses more rows than the limit, duplicates and unknown attributes', function (): void {
        $category = Category::factory()->create();
        $a = Attribute::factory()->create();
        $rows = Attribute::factory()->count(10)->create()->map(fn (Attribute $x): array => ['attribute_id' => $x->id])->all();

        expect(fn () => attrFilters()->replaceSet($category, $rows))->toThrow(AttributeException::class)
            ->and(fn () => attrFilters()->replaceSet($category, [['attribute_id' => $a->id], ['attribute_id' => $a->id]]))->toThrow(AttributeException::class)
            ->and(fn () => attrFilters()->replaceSet($category, [['attribute_id' => 99999]]))->toThrow(AttributeException::class);

        expect(CategoryFilter::query()->count())->toBe(0);
    });

    it('clears the own set so the category inherits again', function (): void {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $a = Attribute::factory()->create();
        attrSetRows($root, [$a]);
        attrSetRows($child, [Attribute::factory()->create()]);

        attrFilters()->clearSet($child);

        expect(attrFilters()->hasOwnSet($child->id))->toBeFalse()
            ->and(attrFilters()->resolve($child->id)->sourceCategoryId)->toBe($root->id);
    });
});
