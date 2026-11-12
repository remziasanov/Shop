<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Models\Category;
use App\Services\Catalog\CatalogVersion;
use App\Settings\CatalogSettings;

/**
 * FL-07, FL-08, FL-10, FL-15, 20.5: the characteristic filters of a category listing.
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
    $this->red = facetValue($this->color, 'red', 'Красный');
    $this->blue = facetValue($this->color, 'blue', 'Синий');
    $this->power = facetAttr('power', AttributeType::Number);
    $this->waterproof = facetAttr('waterproof', AttributeType::Boolean);
    facetSet($this->category, [$this->material, $this->color, $this->power, $this->waterproof]);
});

it('ORs the values of one characteristic (FL-08)', function (): void {
    $a = facetProduct($this->category, [[$this->material, $this->cotton]]);
    $b = facetProduct($this->category, [[$this->material, $this->linen]]);
    facetProduct($this->category, [[$this->material, $this->wool]]);

    expect(facetListed($this->category, ['a' => ['material' => ['cotton', 'linen']]]))->toEqualCanonicalizing([$a->id, $b->id]);
});

it('ANDs different characteristics (FL-08)', function (): void {
    $match = facetProduct($this->category, [[$this->material, $this->cotton], [$this->color, $this->red]]);
    facetProduct($this->category, [[$this->material, $this->cotton], [$this->color, $this->blue]]);
    facetProduct($this->category, [[$this->material, $this->linen], [$this->color, $this->red]]);

    expect(facetListed($this->category, ['a' => ['material' => ['cotton', 'linen'], 'color' => ['red']]]))->toHaveCount(2)
        ->and(facetListed($this->category, ['a' => ['material' => ['cotton'], 'color' => ['red']]]))->toBe([$match->id]);
});

it('matches a product with several values of the characteristic by any of them', function (): void {
    $both = facetProduct($this->category, [[$this->material, [$this->cotton, $this->linen]]]);
    facetProduct($this->category, [[$this->material, $this->wool]]);

    expect(facetListed($this->category, ['a' => ['material' => ['linen']]]))->toBe([$both->id])
        ->and(facetListed($this->category, ['a' => ['material' => ['cotton']]]))->toBe([$both->id])
        // The product is listed once even when it matches two selected values.
        ->and(facetListed($this->category, ['a' => ['material' => ['cotton', 'linen']]]))->toBe([$both->id]);
});

it('includes both ends of a number range and accepts open ends (FL-08)', function (): void {
    $p400 = facetProduct($this->category, [[$this->power, '400']]);
    $p500 = facetProduct($this->category, [[$this->power, '500']]);
    $p900 = facetProduct($this->category, [[$this->power, '900']]);
    $p901 = facetProduct($this->category, [[$this->power, '900.5']]);

    expect(facetListed($this->category, ['a' => ['power' => ['min' => '500', 'max' => '900']]]))->toEqualCanonicalizing([$p500->id, $p900->id])
        ->and(facetListed($this->category, ['a' => ['power' => ['min' => '900']]]))->toEqualCanonicalizing([$p900->id, $p901->id])
        ->and(facetListed($this->category, ['a' => ['power' => ['max' => '500']]]))->toEqualCanonicalizing([$p400->id, $p500->id]);
});

it('swaps a reversed range and accepts a decimal comma', function (): void {
    $p = facetProduct($this->category, [[$this->power, '12.5']]);
    facetProduct($this->category, [[$this->power, '30']]);

    expect(facetListed($this->category, ['a' => ['power' => ['min' => '20', 'max' => '10,5']]]))->toBe([$p->id]);
});

it('keeps only true booleans (FL-08)', function (): void {
    $yes = facetProduct($this->category, [[$this->waterproof, true]]);
    facetProduct($this->category, [[$this->waterproof, false]]);
    facetProduct($this->category);

    expect(facetListed($this->category, ['a' => ['waterproof' => '1']]))->toBe([$yes->id])
        // Anything but 1 is ignored: all three products are shown.
        ->and(facetListed($this->category, ['a' => ['waterproof' => '0']]))->toHaveCount(3);
});

it('excludes products without a value of the filtered characteristic', function (): void {
    $with = facetProduct($this->category, [[$this->material, $this->cotton], [$this->power, '500']]);
    facetProduct($this->category, [[$this->color, $this->red]]);
    facetProduct($this->category);

    expect(facetListed($this->category, ['a' => ['material' => ['cotton']]]))->toBe([$with->id])
        ->and(facetListed($this->category, ['a' => ['power' => ['min' => '0']]]))->toBe([$with->id]);
});

it('combines characteristic filters with the built-in ones and the subcategories', function (): void {
    $child = Category::factory()->childOf($this->category)->create();
    $own = facetProduct($this->category, [[$this->material, $this->cotton]], ['brand' => 'Acme']);
    $inChild = facetProduct($child, [[$this->material, $this->cotton]], ['brand' => 'Acme']);
    facetProduct($child, [[$this->material, $this->cotton]], ['brand' => 'Other']);

    expect(facetListed($this->category, ['a' => ['material' => ['cotton']], 'brands' => ['Acme']]))->toEqualCanonicalizing([$own->id, $inChild->id]);
});

it('ignores unknown, inactive, non-filterable and out-of-set characteristics and unknown values', function (): void {
    $p = facetProduct($this->category, [[$this->material, $this->cotton]]);
    $q = facetProduct($this->category, [[$this->material, $this->linen]]);
    $hidden = facetAttr('hidden', AttributeType::List, ['is_filterable' => false]);
    $off = facetAttr('off', AttributeType::List, ['is_active' => false]);
    $outside = facetAttr('outside');
    facetSet(Category::factory()->create(), [$hidden, $off]);
    facetValue($outside, 'x');

    expect(facetListed($this->category, ['a' => ['nope' => ['x'], 'hidden' => ['x'], 'off' => ['x'], 'outside' => ['x']]]))->toEqualCanonicalizing([$p->id, $q->id])
        // An unknown value drops the whole filter, a known one next to it still works.
        ->and(facetListed($this->category, ['a' => ['material' => ['ghost']]]))->toEqualCanonicalizing([$p->id, $q->id])
        ->and(facetListed($this->category, ['a' => ['material' => ['ghost', 'linen']]]))->toBe([$q->id]);
});

it('does not apply characteristic filters on /catalog and /search (FL-15)', function (): void {
    $p = facetProduct($this->category, [[$this->material, $this->cotton]], ['name' => 'Рубашка']);
    $q = facetProduct($this->category, [[$this->material, $this->linen]], ['name' => 'Рубашка льняная']);

    $catalog = $this->get(route('catalog.index', ['a' => ['material' => ['cotton']]]))->assertOk();
    $search = $this->get(route('search', ['q' => 'Рубашка', 'a' => ['material' => ['cotton']]]))->assertOk();

    expect(collect($catalog->viewData('cards'))->map(fn ($card) => $card->product->id)->all())->toEqualCanonicalizing([$p->id, $q->id])
        ->and(collect($search->viewData('cards'))->map(fn ($card) => $card->product->id)->all())->toEqualCanonicalizing([$p->id, $q->id])
        ->and($catalog->viewData('facets'))->toBe([])
        ->and($search->viewData('facets'))->toBe([]);
});

it('shows no characteristic filters before the migration from JSON has finished (FL-04)', function (): void {
    $settings = app(CatalogSettings::class);
    $settings->attributes_migrated_at = null;
    $settings->save();
    CatalogVersion::bump();

    $p = facetProduct($this->category, [[$this->material, $this->cotton]]);
    $q = facetProduct($this->category, [[$this->material, $this->linen]]);

    $response = $this->get(route('catalog.category', $this->category->slug).'?'.http_build_query(['a' => ['material' => ['cotton']]]))->assertOk();

    expect($response->viewData('facets'))->toBe([])
        ->and(collect($response->viewData('cards'))->map(fn ($card) => $card->product->id)->all())->toEqualCanonicalizing([$p->id, $q->id]);
});

it('uses the filter set inherited from the parent category (FL-06)', function (): void {
    $child = Category::factory()->childOf($this->category)->create();
    $p = facetProduct($child, [[$this->material, $this->cotton]]);
    facetProduct($child, [[$this->material, $this->linen]]);

    expect(facetListed($child, ['a' => ['material' => ['cotton']]]))->toBe([$p->id]);
});

it('does not filter a category whose set lacks the characteristic', function (): void {
    $other = Category::factory()->create();
    facetSet($other, [$this->color]);
    $p = facetProduct($other, [[$this->material, $this->cotton]]);
    $q = facetProduct($other, [[$this->material, $this->linen]]);

    expect(facetListed($other, ['a' => ['material' => ['cotton']]]))->toEqualCanonicalizing([$p->id, $q->id]);
});

it('keeps the characteristic selection in pagination links and the form', function (): void {
    foreach (range(1, 30) as $i) {
        facetProduct($this->category, [[$this->material, $this->cotton]]);
    }

    $response = $this->get(route('catalog.category', $this->category->slug).'?a[material][]=cotton')->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('page=2')
        ->and($html)->toContain('a%5Bmaterial%5D%5B0%5D=cotton')
        ->and($html)->toContain('name="a[material][]"')
        ->and($html)->toContain('value="cotton" checked');
});
