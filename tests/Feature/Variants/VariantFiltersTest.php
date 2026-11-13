<?php

declare(strict_types=1);

use App\Enums\FilterSource;
use App\Exceptions\TooManyFilterParameters;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\OptionType;
use App\Services\Catalog\AttributeFilterParser;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\FacetGroup;
use App\Services\Catalog\FacetService;
use App\Services\Catalog\OptionFilterParser;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/variant_storefront.php';

beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $this->shirt = vsShirt();
    $this->category = $this->shirt['card']->category;
    $this->color = $this->shirt['color'];
    $this->size = $this->shirt['size'];
    $this->url = route('catalog.category', $this->category->slug);

    // A second card: only blue / M (in stock) and a card without variants.
    $this->jacket = variantCard(['name' => 'Куртка', 'sku' => 'JK-CARD', 'category_id' => $this->category->id], $this->color, $this->size);
    $this->blueM = variantOf($this->jacket, [$this->color->values[1], $this->size->values[1]], ['sku' => 'JK-BLUE-M', 'stock_qty' => 3, 'base_price' => '5000.00']);
    $this->plain = sellable(['name' => 'Без модификаций', 'sku' => 'PLAIN', 'category_id' => $this->category->id]);

    optionSet($this->category, [$this->color, $this->size]);
});

/**
 * Own filter set of the category made of options, in the given order.
 *
 * @param  list<OptionType>  $types
 */
function optionSet(Category $category, array $types): void
{
    foreach (array_values($types) as $position => $type) {
        CategoryFilter::factory()->create([
            'category_id' => $category->id,
            'source' => FilterSource::Option,
            'attribute_id' => null,
            'option_type_id' => $type->id,
            'sort_order' => ($position + 1) * 10,
        ]);
    }

    CatalogVersion::bump();
}

/**
 * @return list<string> names of the cards on the page, in order
 */
function vfListed(string $url): array
{
    return array_map(fn ($view) => $view->product->name, test()->get($url)->assertOk()->viewData('cards'));
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, FacetGroup>
 */
function optionFacets(Category $category, array $query = []): array
{
    $filters = (new CatalogFilters)
        ->withAttributes(app(AttributeFilterParser::class)->parse($query, $category->id))
        ->withOptions(app(OptionFilterParser::class)->parse($query, $category->id));
    $groups = app(FacetService::class)->forCategory($category->id, app(CategoryTree::class)->descendantIds($category->id), $filters);

    return collect($groups)->keyBy('slug')->all();
}

/**
 * @return array<string, int>
 */
function optionCountsOf(FacetGroup $group): array
{
    return collect($group->values)->mapWithKeys(fn ($value) => [$value->label => $value->count])->all();
}

it('parses o[slug][]=value with the whitelist and normalization of the characteristics (VR-19, FL-07)', function (): void {
    $red = $this->color->values[0]->slug;
    $m = $this->size->values[1]->slug;

    $selection = app(OptionFilterParser::class)->parse([
        'o' => [$this->size->slug => [$m, 'nope', ''], $this->color->slug => [$red, $red], 'unknown' => ['x']],
        'o_available' => '1',
    ], $this->category->id);

    // Set order (colour first), duplicates and unknown slugs dropped.
    expect($selection->slugs())->toBe([$this->color->slug, $this->size->slug])
        ->and($selection->toQuery())->toBe(['o' => [$this->color->slug => [$red], $this->size->slug => [$m]], 'o_available' => 1])
        ->and($selection->availableOnly)->toBeTrue();

    expect(app(OptionFilterParser::class)->parse(['o' => ['x' => ['y']]], $this->category->id)->isEmpty())->toBeTrue()
        ->and(app(OptionFilterParser::class)->parse(['o' => [$this->color->slug => 'red']], $this->category->id)->isEmpty())->toBeTrue();
});

it('answers 404 for more than ten filled filters in a and o together (FL-07, 20.7)', function (): void {
    $query = ['o' => [$this->color->slug => [$this->color->values[0]->slug]], 'a' => []];

    foreach (range(1, 10) as $i) {
        $query['a']['x'.$i] = ['v'];
    }

    expect(fn () => app(OptionFilterParser::class)->parse($query, $this->category->id))->toThrow(TooManyFilterParameters::class);

    $this->get($this->url.'?'.http_build_query($query))->assertNotFound();
});

it('keeps cards that have an active variant with the selected value and drops the rest (VR-19)', function (): void {
    $blue = $this->color->values[1]->slug;
    $red = $this->color->values[0]->slug;

    expect(vfListed($this->url.'?o[color-slug][]=x'))->toHaveCount(3)
        ->and(vfListed($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$blue]]])))->toEqualCanonicalizing(['Футболка', 'Куртка'])
        ->and(vfListed($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$red]]])))->toBe(['Футболка'])
        ->and(vfListed($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$red, $blue]]])))->toEqualCanonicalizing(['Футболка', 'Куртка']);

    // Inactive variants do not count.
    $this->blueM->forceFill(['is_active' => false])->saveQuietly();
    expect(vfListed($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$blue]]])))->toBe(['Футболка']);
});

it('needs ONE variant that has all the selected values (VR-19)', function (): void {
    $query = fn (string $color, string $size): string => $this->url.'?'.http_build_query(['o' => [$this->color->slug => [$color], $this->size->slug => [$size]]]);
    [$red, $blue] = [$this->color->values[0]->slug, $this->color->values[1]->slug];
    [$s, $m] = [$this->size->values[0]->slug, $this->size->values[1]->slug];

    // The T-shirt has red/S, red/M and blue/S; the jacket only blue/M.
    expect(vfListed($query($red, $m)))->toBe(['Футболка'])
        ->and(vfListed($query($blue, $m)))->toBe(['Куртка'])
        ->and(vfListed($query($blue, $s)))->toBe(['Футболка']);
});

it('«только доступные модификации» keeps cards whose matching variant is in stock (VR-19)', function (): void {
    $red = $this->color->values[0]->slug;
    $m = $this->size->values[1]->slug;
    $only = fn (array $o): string => $this->url.'?'.http_build_query(['o' => $o, 'o_available' => 1]);

    // red / M exists but is out of stock.
    expect(vfListed($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$red], $this->size->slug => [$m]]])))->toBe(['Футболка'])
        ->and(vfListed($only([$this->color->slug => [$red], $this->size->slug => [$m]])))->toBe([])
        ->and(vfListed($only([$this->color->slug => [$red]])))->toBe(['Футболка'])
        // Without a selection the switch alone keeps the cards with an available variant.
        ->and(vfListed($this->url.'?o_available=1'))->toEqualCanonicalizing(['Футболка', 'Куртка']);

    $this->blueM->forceFill(['stock_qty' => 0])->saveQuietly();
    expect(vfListed($this->url.'?o_available=1'))->toBe(['Футболка']);

    // Reserved stock counts against availability.
    $this->shirt['redS']->forceFill(['reserved_qty' => 5])->saveQuietly();
    $this->shirt['blueS']->forceFill(['reserved_qty' => 2])->saveQuietly();
    expect(vfListed($this->url.'?o_available=1'))->toBe([]);
});

it('counts cards per value, each card once, without the option itself and with the others (FL-09, VR-19)', function (): void {
    $facets = optionFacets($this->category);

    // Colour: red is on one card (two variants), blue on two. Size: S on the T-shirt, M on both.
    expect(array_keys($facets))->toBe([$this->color->slug, $this->size->slug])
        ->and(optionCountsOf($facets[$this->color->slug]))->toBe(['Красный' => 1, 'Синий' => 2])
        ->and(optionCountsOf($facets[$this->size->slug]))->toBe(['S' => 1, 'M' => 2])
        ->and($facets[$this->color->slug]->source)->toBe(FacetGroup::SOURCE_OPTION)
        ->and($facets[$this->color->slug]->display)->toBe('color')
        ->and($facets[$this->color->slug]->values[0]->hex)->toBe('#ff0000');

    // Size M selected: colours are counted among cards with an M variant, the size block still offers all sizes.
    $withM = optionFacets($this->category, ['o' => [$this->size->slug => [$this->size->values[1]->slug]]]);

    expect(optionCountsOf($withM[$this->color->slug]))->toBe(['Красный' => 1, 'Синий' => 1])
        ->and(optionCountsOf($withM[$this->size->slug]))->toBe(['S' => 1, 'M' => 2])
        ->and($withM[$this->size->slug]->values[1]->selected)->toBeTrue();

    // Colour red selected: the size counts only the red variants (the same variant must carry both values).
    $withRed = optionFacets($this->category, ['o' => [$this->color->slug => [$this->color->values[0]->slug]]]);
    expect(optionCountsOf($withRed[$this->size->slug]))->toBe(['S' => 1, 'M' => 1]);
});

it('counts only available variants when the switch is on and skips inactive variants and hidden cards (FL-21)', function (): void {
    expect(optionCountsOf(optionFacets($this->category, ['o_available' => 1])[$this->size->slug]))->toBe(['S' => 1, 'M' => 1]);

    $this->shirt['blueS']->forceFill(['is_active' => false])->saveQuietly();
    $this->jacket->forceFill(['is_active' => false])->saveQuietly();
    CatalogVersion::bump();

    expect(optionCountsOf(optionFacets($this->category)[$this->color->slug]))->toBe(['Красный' => 1]);
});

it('selected values stay in the block with a zero counter (FL-09)', function (): void {
    $blue = $this->color->values[1]->slug;
    $facets = optionFacets($this->category, ['o' => [$this->color->slug => [$blue], $this->size->slug => [$this->size->values[0]->slug]], 'o_available' => 1]);

    expect(optionCountsOf($facets[$this->color->slug]))->toHaveKey('Синий');
});

it('runs one counting query per option within the facet budget (FL-10)', function (): void {
    CatalogVersion::bump();
    DB::enableQueryLog();
    optionFacets($this->category);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'product_variant_values'))->count())->toBe(2)
        ->and($queries->count())->toBeLessThanOrEqual(FacetService::MAX_QUERIES + 4);
});

it('renders option blocks with colour swatches, the switch, chips and noindex on the category page (FL-13, VR-19)', function (): void {
    $red = $this->color->values[0]->slug;
    $page = $this->get($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$red]], 'o_available' => 1]))->assertOk();
    $html = $page->getContent();

    expect($html)->toContain('name="o['.$this->color->slug.'][]"')
        ->and($html)->toContain('name="o['.$this->size->slug.'][]"')
        ->and($html)->toContain('background-color: #ff0000')
        ->and($html)->toContain('name="o_available"')
        ->and($html)->toContain('Только доступные модификации')
        ->and($html)->toContain('Цвет: Красный')
        ->and($html)->toContain('<meta name="robots" content="noindex,follow">')
        ->and($html)->toContain('<link rel="canonical" href="'.$this->url.'">');

    // No filters: indexable, no chips, but the blocks and the switch are there.
    $plain = $this->get($this->url)->assertOk()->getContent();
    expect($plain)->toContain('name="o_available"')->and($plain)->not->toContain('noindex');
});

it('has no switch and no option blocks in a category without option rows', function (): void {
    $other = Category::factory()->create(['slug' => 'inoe']);
    sellable(['category_id' => $other->id]);

    $html = $this->get(route('catalog.category', $other->slug))->assertOk()->getContent();

    expect($html)->not->toContain('name="o_available"')->and($html)->not->toContain('name="o[');
});

it('redirects a raw or reordered option query to the normalized URL (FL-07)', function (): void {
    $red = $this->color->values[0]->slug;
    $s = $this->size->values[0]->slug;

    $this->get($this->url.'?'.http_build_query(['o' => [$this->size->slug => [$s], $this->color->slug => [$red]]]))
        ->assertRedirect($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$red], $this->size->slug => [$s]]]))
        ->assertStatus(301);

    $this->get($this->url.'?'.http_build_query(['o' => [$this->color->slug => [$red], $this->size->slug => [$s]]]))->assertOk();
});

it('ignores options of a non-filterable or inactive option type', function (): void {
    $this->color->update(['is_filterable' => false]);
    CatalogVersion::bump();

    expect(array_keys(optionFacets($this->category)))->toBe([$this->size->slug])
        ->and(app(OptionFilterParser::class)->parse(['o' => [$this->color->slug => [$this->color->values[0]->slug]]], $this->category->id)->isEmpty())->toBeTrue();
});

it('inherits the option set of the parent category', function (): void {
    $child = Category::factory()->childOf($this->category)->create(['slug' => 'mayki']);
    $card = variantCard(['name' => 'Майка', 'sku' => 'MK', 'category_id' => $child->id], $this->color);
    variantOf($card, [$this->color->values[0]], ['sku' => 'MK-RED']);

    $facets = optionFacets($child);

    expect(array_keys($facets))->toBe([$this->color->slug])->and(optionCountsOf($facets[$this->color->slug]))->toBe(['Красный' => 1]);
});
