<?php

declare(strict_types=1);

use App\Enums\PriceDisplayMode;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Services\Pricing\GroupPriceRecalculator;
use App\Settings\PricingSettings;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => false]);
    $this->category = Category::factory()->create();
    $this->group = CustomerGroup::factory()->discount(10)->showRetailPrice()->create(['name' => 'Опт']);

    $make = fn (string $name, string $price) => Product::factory()->inCategory($this->category)->create(['name' => $name, 'base_price' => $price, 'brand' => null]);
    // Retail order: Cheap < Middle < Dear. The exact price of the group turns Dear into the cheapest item for it.
    $this->cheap = $make('Дешёвый', '500.00');
    $this->middle = $make('Средний', '700.00');
    $this->dear = $make('Дорогой', '1000.00');
    gpTier($this->dear, $this->group, 1, '300.00');
    app(GroupPriceRecalculator::class)->recalculate([$this->dear->id]);
});

it('sorts the catalog by the price of the viewer group (GP-08)', function (): void {
    $names = ['Дешёвый', 'Средний', 'Дорогой'];

    $guest = $this->get(route('catalog.index', ['sort' => 'price_asc']))->assertOk()->getContent();
    expect(gpListedNames($guest, $names))->toBe(['Дешёвый', 'Средний', 'Дорогой']);

    gpSignInAs($this, $this->group);
    $asc = $this->get(route('catalog.index', ['sort' => 'price_asc']))->getContent();
    $desc = $this->get(route('catalog.index', ['sort' => 'price_desc']))->getContent();

    // 300 (exact), 450 (500 - 10 %), 630 (700 - 10 %).
    expect(gpListedNames($asc, $names))->toBe(['Дорогой', 'Дешёвый', 'Средний'])
        ->and(gpListedNames($desc, $names))->toBe(['Средний', 'Дешёвый', 'Дорогой']);
});

it('filters the catalog by the price of the viewer group (GP-08)', function (): void {
    $names = ['Дешёвый', 'Средний', 'Дорогой'];

    // A guest: Dear costs 1 000 and is the only item from 800.
    expect(gpListedNames($this->get(route('catalog.index', ['price_from' => '800']))->getContent(), $names))->toBe(['Дорогой']);

    gpSignInAs($this, $this->group);

    // The group pays 300 / 450 / 630: from 400 to 650 only Cheap and Middle match; up to 400 only Dear.
    expect(gpListedNames($this->get(route('catalog.index', ['price_from' => '400', 'price_to' => '650', 'sort' => 'price_asc']))->getContent(), $names))->toBe(['Дешёвый', 'Средний'])
        ->and(gpListedNames($this->get(route('catalog.index', ['price_to' => '400']))->getContent(), $names))->toBe(['Дорогой'])
        ->and(gpListedNames($this->get(route('catalog.index', ['price_from' => '800']))->getContent(), $names))->toBe([]);
});

it('behaves like retail for a group without own prices (GP-08)', function (): void {
    $plain = CustomerGroup::factory()->create();
    gpSignInAs($this, $plain);
    $names = ['Дешёвый', 'Средний', 'Дорогой'];

    expect(gpListedNames($this->get(route('catalog.index', ['sort' => 'price_desc']))->getContent(), $names))->toBe(['Дорогой', 'Средний', 'Дешёвый'])
        ->and(gpListedNames($this->get(route('catalog.index', ['price_from' => '600', 'price_to' => '800']))->getContent(), $names))->toBe(['Средний']);
});

it('filters a card with variants by its dearest variant in the group (VR-07, GP-08)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard(['name' => 'Куртка'], $size);
    variantOf($card, [$size->values[0]], ['base_price' => '1000.00']);
    $dear = variantOf($card, [$size->values[1]], ['base_price' => '2000.00']);
    gpTier($dear, $this->group, 1, '1500.00');
    app(GroupPriceRecalculator::class)->recalculate([$card->id]);
    $names = ['Куртка'];

    // Retail the card spans 1 000 … 2 000; for the group 900 (1 000 - 10 %) … 1 500 (exact).
    expect(gpListedNames($this->get(route('catalog.index', ['price_from' => '1800']))->getContent(), $names))->toBe(['Куртка']);

    gpSignInAs($this, $this->group);

    expect(gpListedNames($this->get(route('catalog.index', ['price_from' => '1800']))->getContent(), $names))->toBe([])
        ->and(gpListedNames($this->get(route('catalog.index', ['price_from' => '1400']))->getContent(), $names))->toBe(['Куртка']);
});

it('shows the group price with the retail price struck through and the first tier (GP-09)', function (): void {
    gpTier($this->middle, $this->group, 10, '500.00');
    gpTier($this->middle, $this->group, 50, '400.00');

    $html = $this->get(route('catalog.index'))->assertOk()->getContent();
    expect($html)->not->toContain('data-retail-price')->and($html)->not->toContain('data-tier-caption');

    gpSignInAs($this, $this->group);
    $response = $this->get(route('catalog.index'))->assertOk();

    $response->assertSeeText("630\u{00A0}₽")             // Middle at the group discount
        ->assertSeeText("700\u{00A0}₽")                   // ... with the retail price struck through
        ->assertSeeText("от 10 шт — 500\u{00A0}₽")        // first tier caption
        ->assertDontSeeText('от 50 шт');
    expect($response->getContent())->toContain('data-retail-price');
});

it('does not strike the retail price for a group that has it switched off (GP-09)', function (): void {
    $group = CustomerGroup::factory()->discount(10)->create(['show_retail_price' => false]);
    gpSignInAs($this, $group);

    $this->get(route('catalog.index'))->assertOk()->assertSeeText("630\u{00A0}₽")->assertDontSee('data-retail-price', false);
});

it('takes the sale label and its percent from the old price of the viewer (GP-09)', function (): void {
    $sale = Product::factory()->inCategory($this->category)->create(['name' => 'Акционный', 'base_price' => '1000.00', 'compare_at_price' => '1250.00', 'brand' => null]);
    $plain = CustomerGroup::factory()->create();

    // A guest: 1 000 against the compare-at price 1 250 is −20 %.
    $this->get(route('catalog.index'))->assertSeeText('−20%');
    expect(gpListedNames($this->get(route('catalog.index', ['flags' => ['sale']]))->getContent(), ['Акционный', 'Дешёвый']))->toBe(['Акционный']);

    // A group with a discount: its own row, 900 with the old price 1 250 → 1 250 over 900 is −28 %; the sale filter follows the viewer's old price.
    gpSignInAs($this, $this->group);
    expect(gpRow($sale->fresh(), $this->group))->toBe(['final' => '900.00', 'old' => '1250.00']);
    $html = $this->get(route('catalog.index'))->getContent();
    expect($html)->toContain('−28%')->not->toContain('−20%');
    expect(gpListedNames($this->get(route('catalog.index', ['flags' => ['sale']]))->getContent(), ['Акционный', 'Дешёвый']))->toBe(['Акционный']);
});

it('loads the prices of a page in a fixed number of queries (GP-03, NF-02)', function (): void {
    gpSignInAs($this, $this->group);
    $this->get(route('catalog.index'))->assertOk();

    foreach (range(1, 12) as $i) {
        Product::factory()->inCategory($this->category)->create(['name' => 'Ещё '.$i, 'base_price' => '100.00']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('catalog.index'))->assertOk();
    $queries = array_filter(DB::getQueryLog(), fn (array $q): bool => (str_contains($q['query'], 'product_price_tiers') || str_contains($q['query'], 'product_group_prices_final')) && ! str_contains($q['query'], 'customer_groups'));
    DB::disableQueryLog();

    // One load of tiers and one of rows for the whole page, not per card.
    expect(count($queries))->toBeLessThanOrEqual(2);
});

it('shows the prices of the viewer group only: no other group price in HTML (21.7)', function (): void {
    $other = CustomerGroup::factory()->discount(50)->create();
    gpTier($this->middle, $other, 5, '123.45');
    gpTier($this->middle, $this->group, 5, '555.55');
    app(GroupPriceRecalculator::class)->recalculate([$this->middle->id]);

    // A guest sees neither group's numbers on the list, the card or in JSON-LD.
    foreach ([route('catalog.index'), route('product.show', $this->middle->slug)] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('123,45')->not->toContain('555,55')->not->toContain('123.45')->not->toContain('555.55')
            ->and($html)->not->toContain("350\u{00A0}₽");
    }

    gpSignInAs($this, $this->group);
    $html = $this->get(route('product.show', $this->middle->slug))->assertOk()->getContent();

    expect($html)->toContain('55555')->not->toContain('12345')->not->toContain('123,45')->not->toContain('350');
});

it('keeps prices out of listings, suggestions and JSON-LD of a group without price display (GP-20, CG-06)', function (): void {
    $hidden = CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Request)->create();
    gpTier($this->middle, $hidden, 10, '500.00');
    gpSignInAs($this, $hidden);

    $list = $this->get(route('catalog.index'))->assertOk()->getContent();
    expect($list)->not->toContain('data-tier-caption')->and($list)->not->toContain("700\u{00A0}₽");

    $page = $this->get(route('product.show', $this->middle->slug))->assertOk()->getContent();
    expect($page)->not->toContain('data-tier-table')->and($page)->not->toContain('"price"')->and($page)->not->toContain('tierPrice(');

    $suggest = $this->getJson(route('search.suggest', ['q' => 'Средний']))->assertOk()->json('items');
    expect($suggest)->not->toBeEmpty()->and($suggest[0]['price'])->toBeNull();
});

it('does not query group prices for a group without price display (GP-20)', function (): void {
    $hidden = CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Request)->create();
    gpSignInAs($this, $hidden);

    DB::enableQueryLog();
    $this->get(route('catalog.index'))->assertOk();
    $log = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(array_filter($log, fn (string $q): bool => (str_contains($q, 'product_price_tiers') || str_contains($q, 'product_group_prices_final')) && ! str_contains($q, 'customer_groups')))->toBe([]);
});

it('puts the price in the JSON-LD of the viewer group (GP-09)', function (): void {
    gpSignInAs($this, $this->group);
    $html = $this->get(route('product.show', $this->middle->slug))->assertOk()->getContent();

    expect($html)->toContain('"price":"630.00"');
});

it('keeps hit and new labels untouched by the group price (retail behaviour)', function (): void {
    app(PricingSettings::class);
    Product::factory()->inCategory($this->category)->create(['name' => 'Новинка', 'base_price' => '100.00', 'is_new' => true, 'brand' => null]);

    $this->get(route('catalog.index'))->assertOk()->assertSee('Новинка');
});
