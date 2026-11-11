<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Exceptions\TooManyFilterParameters;
use App\Models\Category;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\AttributeFilterParser;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\FacetService;
use App\Settings\CatalogSettings;

/**
 * FL-07, 20.7: parsing, whitelisting, normalization and limits of the characteristic filters.
 */
beforeEach(function (): void {
    $this->withoutVite();
    attrMigrated();

    $this->category = Category::factory()->create();
    $this->material = facetAttr('material');
    $this->cotton = facetValue($this->material, 'cotton', 'Хлопок', 1);
    $this->linen = facetValue($this->material, 'linen', 'Лён', 2);
    $this->power = facetAttr('power', AttributeType::Number);
    $this->waterproof = facetAttr('waterproof', AttributeType::Boolean);
    facetSet($this->category, [$this->material, $this->power, $this->waterproof]);
});

function parseFilters(Category $category, array $query): array
{
    return app(AttributeFilterParser::class)->parse($query, $category->id)->toQuery();
}

it('normalizes the order: attributes in set order, values in dictionary order', function (): void {
    $a = parseFilters($this->category, ['a' => ['waterproof' => '1', 'power' => ['max' => '900', 'min' => '500'], 'material' => ['linen', 'cotton']]]);
    $b = parseFilters($this->category, ['a' => ['material' => ['cotton', 'linen'], 'power' => ['min' => '500', 'max' => '900'], 'waterproof' => '1']]);

    expect($a)->toBe($b)
        ->and(array_keys($a['a']))->toBe(['material', 'power', 'waterproof'])
        ->and($a['a']['material'])->toBe(['cotton', 'linen'])
        ->and($a['a']['power'])->toBe(['min' => '500', 'max' => '900'])
        ->and($a['a']['waterproof'])->toBe(1);
});

it('yields the same URL and the same facet cache key for the same selection (FL-07, FL-11)', function (): void {
    $first = $this->get(route('catalog.category', $this->category->slug).'?a[power][max]=900&a[material][]=linen&a[material][]=cotton&a[power][min]=500');
    $second = $this->get(route('catalog.category', $this->category->slug).'?a[material][]=cotton&a[material][]=linen&a[power][min]=500&a[power][max]=900');

    expect($first->viewData('filters')->toQuery())->toBe($second->viewData('filters')->toQuery())
        ->and($first->viewData('canonical'))->toBe($second->viewData('canonical'))
        ->and(app(FacetService::class)->cacheKey($this->category->id, $first->viewData('filters')))
        ->toBe(app(FacetService::class)->cacheKey($this->category->id, $second->viewData('filters')));
});

it('clamps and cleans decimals', function (): void {
    $query = parseFilters($this->category, ['a' => ['power' => ['min' => '-0', 'max' => '99999999999999']]]);
    expect($query['a']['power'])->toBe(['min' => '0', 'max' => '9999999999.9999']);

    $query = parseFilters($this->category, ['a' => ['power' => ['min' => '12,34567', 'max' => '1e5']]]);
    expect($query['a']['power'])->toBe(['min' => '12.3456']);

    expect(parseFilters($this->category, ['a' => ['power' => ['min' => 'abc', 'max' => '']]]))->toBe([])
        ->and(parseFilters($this->category, ['a' => ['power' => '500']]))->toBe([]);
});

it('drops values of the wrong shape', function (): void {
    expect(parseFilters($this->category, ['a' => ['material' => 'cotton']]))->toBe([])
        ->and(parseFilters($this->category, ['a' => ['material' => [['x']]]]))->toBe([])
        ->and(parseFilters($this->category, ['a' => ['waterproof' => ['1']]]))->toBe([])
        ->and(parseFilters($this->category, ['a' => 'x']))->toBe([])
        ->and(parseFilters($this->category, ['a' => ['power' => ['min' => ['1']]]]))->toBe([]);
});

it('reads at most 20 values per characteristic', function (): void {
    foreach (range(1, 30) as $i) {
        facetValue($this->material, 'v'.$i, 'V'.$i, 10 + $i);
    }

    $slugs = array_map(fn (int $i): string => 'v'.$i, range(1, 30));
    $selection = app(AttributeFilterParser::class)->parse(['a' => ['material' => $slugs]], $this->category->id);

    expect($selection->get('material')->values)->toHaveCount(20)
        ->and(array_column($selection->get('material')->values, 'slug'))->toBe(array_slice($slugs, 0, 20));
});

it('answers 404 for more than 10 characteristic filters (20.7)', function (): void {
    $many = [];
    foreach (range(1, 11) as $i) {
        $many['x'.$i] = ['v'];
    }

    expect(fn () => app(AttributeFilterParser::class)->parse(['a' => $many], $this->category->id))->toThrow(TooManyFilterParameters::class);

    $this->get(route('catalog.category', $this->category->slug).'?'.http_build_query(['a' => $many]))->assertNotFound();

    // Ten are fine, and empty form inputs do not count.
    $ten = array_slice($many, 0, 10);
    $this->get(route('catalog.category', $this->category->slug).'?'.http_build_query(['a' => $ten]))->assertOk();
    $this->get(route('catalog.category', $this->category->slug).'?'.http_build_query(['a' => $ten + ['e1' => '', 'e2' => ['min' => '', 'max' => '']]]))->assertOk();
});

it('keeps only the filters of the effective set, none without a set', function (): void {
    $other = Category::factory()->create();
    $parser = app(AttributeFilterParser::class);

    expect($parser->parse(['a' => ['material' => ['cotton']]], $other->id)->isEmpty())->toBeTrue();

    $child = Category::factory()->childOf($this->category)->create();
    expect($parser->parse(['a' => ['material' => ['cotton']]], $child->id)->count())->toBe(1);
});

it('limits a set to filters_max_per_category characteristics', function (): void {
    $settings = app(CatalogSettings::class);
    $settings->filters_max_per_category = 1;
    $settings->save();
    CatalogVersion::bump();

    $parser = app(AttributeFilterParser::class);

    expect($parser->parse(['a' => ['material' => ['cotton'], 'waterproof' => '1']], $this->category->id)->count())->toBe(1);
});

it('keeps the characteristic filters through restrictedTo and counts active filters', function (): void {
    $selection = app(AttributeFilterParser::class)->parse(['a' => ['material' => ['cotton'], 'power' => ['min' => '5']]], $this->category->id);
    $filters = (new CatalogFilters(priceFrom: 100, brands: ['A']))->withAttributes($selection);

    $restricted = $filters->restrictedTo(app(ViewerContext::class)->access());

    expect($restricted->attributes->count())->toBe(2)
        ->and($filters->activeCount())->toBe(4)
        ->and($filters->hasFilters())->toBeTrue()
        ->and($filters->toQuery()['a']['material'])->toBe(['cotton']);
});
