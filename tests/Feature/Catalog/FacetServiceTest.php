<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Enums\CategoryAccessMode;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\ProductAttributeValue;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\AttributeFilterParser;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\FacetGroup;
use App\Services\Catalog\FacetService;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FL-09…FL-11, FL-21, 20.5: facets of the category filters.
 */
beforeEach(function (): void {
    $this->withoutVite();
    attrMigrated();

    $this->category = Category::factory()->create();
    $this->material = facetAttr('material');
    $this->cotton = facetValue($this->material, 'cotton', 'Хлопок', 1);
    $this->linen = facetValue($this->material, 'linen', 'Лён', 2);
    $this->wool = facetValue($this->material, 'wool', 'Шерсть', 3);
    $this->color = facetAttr('color');
    $this->red = facetValue($this->color, 'red', 'Красный', 1);
    $this->blue = facetValue($this->color, 'blue', 'Синий', 2);
    $this->power = facetAttr('power', AttributeType::Number);
    $this->waterproof = facetAttr('waterproof', AttributeType::Boolean);
    facetSet($this->category, [$this->material, $this->color, $this->power, $this->waterproof]);
});

/**
 * @param  array<string, mixed>  $query
 * @return array<string, FacetGroup>
 */
function facetsFor(Category $category, array $query = []): array
{
    $filters = (new CatalogFilters)->withAttributes(app(AttributeFilterParser::class)->parse($query, $category->id));
    $groups = app(FacetService::class)->forCategory($category->id, app(CategoryTree::class)->descendantIds($category->id), $filters);

    return collect($groups)->keyBy('slug')->all();
}

/** @return array<string, int> */
function facetCounts(FacetGroup $group): array
{
    return collect($group->values)->mapWithKeys(fn ($value) => [$value->slug => $value->count])->all();
}

it('counts the products of the selection per value (FL-09)', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    facetProduct($this->category, [[$this->material, [$this->cotton, $this->linen]]]);

    $facets = facetsFor($this->category);

    expect(facetCounts($facets['material']))->toBe(['cotton' => 3, 'linen' => 1]);
});

it('lists only values present in the selection and omits empty blocks', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    // `wool` has no product here, `color` has no values at all.
    $facets = facetsFor($this->category);

    expect(array_keys(facetCounts($facets['material'])))->toBe(['cotton'])
        ->and($facets)->not->toHaveKey('color')
        ->and($facets)->not->toHaveKey('power')
        ->and($facets)->toHaveKey('waterproof');
});

it('counts a characteristic without its own filter but with all the others (FL-09)', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton], [$this->color, $this->red]]);
    facetProduct($this->category, [[$this->material, $this->linen], [$this->color, $this->red]]);
    facetProduct($this->category, [[$this->material, $this->linen], [$this->color, $this->blue]]);
    facetProduct($this->category, [[$this->material, $this->wool], [$this->color, $this->blue]]);

    $facets = facetsFor($this->category, ['a' => ['material' => ['cotton'], 'color' => ['red']]]);

    // material: color=red applied, material itself not -> cotton 1, linen 1 (wool is blue).
    expect(facetCounts($facets['material']))->toBe(['cotton' => 1, 'linen' => 1])
        // color: material=cotton applied, color itself not -> red 1.
        ->and(facetCounts($facets['color']))->toBe(['red' => 1]);
});

it('keeps a selected value visible at zero (FL-09)', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton], [$this->color, $this->red]]);
    facetProduct($this->category, [[$this->material, $this->linen], [$this->color, $this->blue]]);

    $facets = facetsFor($this->category, ['a' => ['material' => ['wool'], 'color' => ['red']]]);

    // color=red leaves no wool product: the selected value still shows, with 0, in dictionary order.
    expect(facetCounts($facets['material']))->toBe(['cotton' => 1, 'wool' => 0])
        ->and(collect($facets['material']->values)->firstWhere('slug', 'wool')->selected)->toBeTrue()
        ->and(collect($facets['material']->values)->firstWhere('slug', 'cotton')->selected)->toBeFalse();
});

it('applies the built-in filters to the counters', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton]], ['brand' => 'Acme']);
    facetProduct($this->category, [[$this->material, $this->linen]], ['brand' => 'Other']);

    $filters = (new CatalogFilters(brands: ['Acme']));
    $groups = app(FacetService::class)->forCategory($this->category->id, [$this->category->id], $filters);

    expect(facetCounts($groups[0]))->toBe(['cotton' => 1]);
});

it('gives the minimum and maximum of the selection for numbers (FL-09)', function (): void {
    facetProduct($this->category, [[$this->power, '120.5']]);
    facetProduct($this->category, [[$this->power, '900']]);
    facetProduct($this->category, [[$this->power, '450'], [$this->material, $this->cotton]]);
    facetProduct($this->category);

    $facets = facetsFor($this->category);

    expect($facets['power']->min)->toBe('120.5')
        ->and($facets['power']->max)->toBe('900');

    // Other filters narrow the hint, the number's own range does not.
    $narrow = facetsFor($this->category, ['a' => ['material' => ['cotton'], 'power' => ['min' => '500']]]);
    expect($narrow['power']->min)->toBe('450')
        ->and($narrow['power']->max)->toBe('450')
        ->and($narrow['power']->selectedMin)->toBe('500');
});

it('marks a checked boolean', function (): void {
    facetProduct($this->category, [[$this->waterproof, true]]);

    expect(facetsFor($this->category)['waterproof']->checked)->toBeFalse()
        ->and(facetsFor($this->category, ['a' => ['waterproof' => '1']])['waterproof']->checked)->toBeTrue();
});

it('counts the subtree and uses the inherited set', function (): void {
    $child = Category::factory()->childOf($this->category)->create();
    facetProduct($child, [[$this->material, $this->cotton]]);
    facetProduct($this->category, [[$this->material, $this->cotton]]);

    expect(facetCounts(facetsFor($this->category)['material']))->toBe(['cotton' => 2])
        ->and(facetCounts(facetsFor($child)['material']))->toBe(['cotton' => 1]);
});

it('leaves inactive products and products of hidden categories out of the counters (FL-21)', function (): void {
    $hidden = Category::factory()->childOf($this->category)->create();
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    facetProduct($this->category, [[$this->material, $this->cotton]], ['is_active' => false]);
    facetProduct($hidden, [[$this->material, $this->cotton]]);
    facetProduct($hidden, [[$this->material, $this->wool]]);

    expect(facetCounts(facetsFor($this->category)['material']))->toBe(['cotton' => 2, 'wool' => 1]);

    // The guest group may not see the child category: its products vanish from the counters.
    configureGuest([], CategoryAccessMode::AllExcept, [$hidden]);
    app(ViewerContext::class)->beginRequest();

    expect(facetCounts(facetsFor($this->category)['material']))->toBe(['cotton' => 1]);
});

it('caches facets by group, category, normalized parameters and catalog version (FL-11)', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    $service = app(FacetService::class);
    $filters = (new CatalogFilters)->withAttributes(app(AttributeFilterParser::class)->parse(['a' => ['material' => ['cotton']]], $this->category->id));

    $key = $service->cacheKey($this->category->id, $filters);

    expect($key)->toStartWith('facets:'.guestGroup()->id.':'.$this->category->id.':')
        ->and($key)->toEndWith(':'.CatalogVersion::current())
        ->and($key)->toContain(sha1((string) json_encode($filters->toQuery(false))));

    facetsFor($this->category, ['a' => ['material' => ['cotton']]]);
    expect(Cache::has($key))->toBeTrue();

    // Second call: no SQL at all.
    DB::flushQueryLog();
    DB::enableQueryLog();
    facetsFor($this->category, ['a' => ['material' => ['cotton']]]);
    $facetQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_attribute_values'))->count();
    DB::disableQueryLog();
    expect($facetQueries)->toBe(0);

    // A new catalog version is a new key, and the data are rebuilt.
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    CatalogVersion::bump();
    expect($service->cacheKey($this->category->id, $filters))->not->toBe($key)
        ->and(facetCounts(facetsFor($this->category)['material']))->toBe(['cotton' => 2]);
});

it('keeps separate cache entries per customer group and per selection (FL-11)', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    $service = app(FacetService::class);
    $none = new CatalogFilters;
    $some = (new CatalogFilters)->withAttributes(app(AttributeFilterParser::class)->parse(['a' => ['material' => ['cotton']]], $this->category->id));

    $guestKey = $service->cacheKey($this->category->id, $none);
    $other = CustomerGroup::factory()->create();
    app(ViewerContext::class)->actingAs($other);

    expect($service->cacheKey($this->category->id, $none))->not->toBe($guestKey)
        ->and($service->cacheKey($this->category->id, $none))->toStartWith('facets:'.$other->id.':')
        ->and($service->cacheKey($this->category->id, $some))->not->toBe($service->cacheKey($this->category->id, $none));
});

it('does not cache when facets_cache_minutes is 0', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton]]);
    $settings = app(CatalogSettings::class);
    $settings->facets_cache_minutes = 0;
    $settings->save();

    facetsFor($this->category);

    expect(Cache::has(app(FacetService::class)->cacheKey($this->category->id, new CatalogFilters)))->toBeFalse();
});

it('stays within 10 facet queries even with a set larger than the budget (FL-10)', function (): void {
    $category = Category::factory()->create();
    $attributes = [];

    foreach (range(1, 12) as $i) {
        $attribute = facetAttr('list'.$i);
        $value = facetValue($attribute, 'v'.$i);
        $attributes[] = $attribute;
        $first = $first ?? facetProduct($category);
        ProductAttributeValue::query()->create(['product_id' => $first->id, 'attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]);
    }

    $number = facetAttr('num', AttributeType::Number);
    $settings = app(CatalogSettings::class);
    $settings->filters_max_per_category = 20;
    $settings->save();
    facetSet($category, [...$attributes, $number]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $groups = app(FacetService::class)->forCategory($category->id, [$category->id], new CatalogFilters);
    $facetQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_attribute_values'))->count();
    DB::disableQueryLog();

    expect($facetQueries)->toBeLessThanOrEqual(FacetService::MAX_QUERIES)
        // 9 list blocks + the number query; the 10th and later list filters get no block.
        ->and(count($groups))->toBe(9);
});

it('renders the filter block with counters, ranges and chips (FL-13)', function (): void {
    facetProduct($this->category, [[$this->material, $this->cotton], [$this->power, '500'], [$this->waterproof, true]]);
    facetProduct($this->category, [[$this->material, $this->linen], [$this->power, '900']]);

    $html = $this->get(route('catalog.category', $this->category->slug).'?a[material][]=cotton&a[power][min]=100')->assertOk()->getContent();

    expect($html)->toContain('name="a[material][]" value="cotton" checked')
        ->and($html)->toContain('name="a[material][]" value="linen"')
        ->and($html)->toContain('name="a[power][min]"')
        ->and($html)->toContain('name="a[power][max]"')
        ->and($html)->toContain('placeholder="500"')
        ->and(substr_count($html, 'placeholder="500"'))->toBe(2)
        ->and($html)->toContain('name="a[waterproof]" value="1"')
        // Active chips with «Сбросить всё» and the mobile button with the count.
        ->and($html)->toContain('Material: Хлопок')
        ->and($html)->toContain('Power: от 100 Вт')
        ->and($html)->toContain('Сбросить всё')
        ->and($html)->toContain('Фильтры (2)')
        ->and($html)->toContain('Показать 1 товар')
        ->and($html)->toContain('debounce.400ms');
});
