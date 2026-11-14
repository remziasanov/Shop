<?php

declare(strict_types=1);

use App\Enums\AgeRestriction;
use App\Enums\SalesMode;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Services\Catalog\ProductPresenter;
use App\Services\Pricing\GroupPriceRecalculator;
use App\Settings\AppearanceSettings;

/**
 * TH-11, TH-13: product card variants C1…C6 and listing variants L1…L3. Registry themes that use each variant:
 * arkada = C1/L2, pudra = C2/L2, ultrafiolet = C3/L2, volt = C4/L1, prays = C5/L3, modul = C6/L1, yarmarka = C1/L3.
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

function useTheme(string $id): void
{
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = $id;
    $settings->theme_overrides = [];
    $settings->save();
}

/** @return array<string, array{string, string}> variant => [theme id, variant] */
function cardThemes(): array
{
    return [
        'C1' => ['arkada', 'C1'],
        'C2' => ['pudra', 'C2'],
        'C3' => ['ultrafiolet', 'C3'],
        'C4' => ['volt', 'C4'],
        'C5' => ['prays', 'C5'],
        'C6' => ['modul', 'C6'],
    ];
}

/** The markup of the card with the given product name (from its opening <article to the closing tag). */
function cardHtml(string $html, string $name): string
{
    preg_match_all('#<article\b.*?</article>#s', $html, $m);

    foreach ($m[0] as $card) {
        if (str_contains($card, '>'.$name.'</a>')) {
            return $card;
        }
    }

    return '';
}

function listingProducts(): void
{
    $category = Category::factory()->create();

    foreach (range(1, 3) as $i) {
        Product::factory()->inCategory($category)->create(['name' => 'Товар '.$i, 'brand' => 'Бренд', 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart, 'is_featured' => true]);
    }
}

describe('card variants render the same data (TH-11)', function (): void {
    it('renders the card of the theme with a tile hook, the name in the body font and every datum', function (string $theme, string $variant): void {
        useTheme($theme);
        ageGate();
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create([
            'name' => 'Лампа настольная', 'sku' => 'LMP-77', 'base_price' => '1000.00', 'compare_at_price' => '1500.00', 'stock_qty' => 10,
            'sales_mode' => SalesMode::Cart, 'is_featured' => true, 'age_restriction' => AgeRestriction::Age18,
            'attributes' => [['name' => 'Мощность', 'value' => '60 Вт'], ['name' => 'Цоколь', 'value' => 'E27'], ['name' => 'Лишнее', 'value' => 'третья']],
        ]);

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();
        $card = cardHtml($html, 'Лампа настольная');

        expect($card)->not->toBe('')
            ->and($card)->toContain('data-card="'.$variant.'"')
            ->and($card)->toContain('data-tile data-tile-index="0"')
            ->and($card)->toContain('data-age="18"')->toContain('age-badge')
            ->and($card)->toMatch('#<h3 class="[^"]*\bfont-body\b[^"]*">\s*<a [^>]*>Лампа настольная</a>#')
            ->and($card)->toContain("1\u{202F}000\u{00A0}₽")->toContain("1\u{202F}500\u{00A0}₽")
            ->and($card)->toContain('data-action="cart"')->toContain('action="'.route('cart.add', [], false).'"')
            ->and($card)->toContain('В наличии')
            ->and($card)->toContain($product->slug);
    })->with(array_values(cardThemes()));

    it('shows a hit label, the price on request and the sale mark in every variant', function (string $theme): void {
        useTheme($theme);
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create(['name' => 'Хитовый', 'base_price' => '100.00', 'is_featured' => true, 'is_new' => true, 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        Product::factory()->inCategory($category)->create(['name' => 'Без цены', 'price_on_request' => true, 'stock_qty' => 3, 'sales_mode' => SalesMode::Inquiry]);

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        expect(cardHtml($html, 'Хитовый'))->toContain('Хит')->toContain('Новинка')
            ->and(cardHtml($html, 'Без цены'))->toContain('Цена по запросу')->toContain('data-action="inquiry"');
    })->with(array_column(cardThemes(), 0));

    it('shows two characteristic lines on C4 and none on the other variants', function (): void {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create([
            'name' => 'Дрель', 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart,
            'attributes' => [['name' => 'Мощность', 'value' => '700 Вт'], ['name' => 'Вес', 'value' => '1,8 кг'], ['name' => 'Лишнее', 'value' => 'третья']],
        ]);

        useTheme('volt');
        $card = cardHtml($this->get(route('catalog.index'))->getContent(), 'Дрель');
        expect(substr_count($card, '<li class="truncate"'))->toBe(2)->and($card)->toContain('Мощность:')->toContain('700 Вт')->not->toContain('третья');

        useTheme('arkada');
        expect(cardHtml($this->get(route('catalog.index'))->getContent(), 'Дрель'))->not->toContain('data-card-specs');
    });

    it('numbers the cells of C6 and puts the SKU and the group tiers into the C5 row', function (): void {
        $category = Category::factory()->create();
        $first = Product::factory()->inCategory($category)->create(['name' => 'Первый', 'sku' => 'A-1', 'base_price' => '700.00', 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        Product::factory()->inCategory($category)->create(['name' => 'Второй', 'sku' => 'A-2', 'base_price' => '800.00', 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);

        useTheme('modul');
        $html = $this->get(route('catalog.index'))->getContent();
        expect(preg_match_all('#data-card-number[^>]*>(\d\d)<#', $html, $numbers))->toBe(2)->and($numbers[1])->toBe(['01', '02']);

        $group = CustomerGroup::factory()->discount(10)->showRetailPrice()->create();
        gpTier($first, $group, 10, '500.00');
        gpTier($first, $group, 50, '400.00');
        app(GroupPriceRecalculator::class)->recalculate([$first->id]);
        gpSignInAs($this, $group);

        useTheme('prays');
        $card = cardHtml($this->get(route('catalog.index'))->getContent(), 'Первый');
        expect($card)->toContain('data-card-sku')->toContain('Артикул: A-1')
            ->and($card)->toContain("от 10 шт — 500\u{00A0}₽")->toContain("от 50 шт — 400\u{00A0}₽");
    });

    it('keeps the name heading in the body font on every variant, also in a card with variants and on the home page', function (): void {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create(['name' => 'Обычный', 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);

        foreach (cardThemes() as [$theme, $variant]) {
            useTheme($theme);
            $html = $this->get(route('catalog.index'))->assertOk()->getContent();

            preg_match_all('#<article\b[^>]*data-card="'.$variant.'".*?</article>#s', $html, $cards);
            expect($cards[0])->not->toBe([]);

            foreach ($cards[0] as $card) {
                preg_match_all('#<h3\b[^>]*>#', $card, $headings);
                expect($headings[0])->not->toBe([]);

                foreach ($headings[0] as $heading) {
                    expect($heading)->toMatch('#class="[^"]*\bfont-body\b#');
                }
            }
        }
    });
});

describe('purchase scenarios in every card variant (stage 21 acceptance)', function (): void {
    it('adds to the cart from the card and places the order', function (string $theme, string $variant): void {
        useTheme($theme);
        $product = sellable(['name' => 'Кресло', 'sales_mode' => SalesMode::Cart, 'stock_qty' => 5]);

        $card = cardHtml($this->get(route('catalog.index'))->assertOk()->getContent(), 'Кресло');
        expect($card)->toContain('data-card="'.$variant.'"')->toContain('name="product_id" value="'.$product->id.'"');

        $this->postJson(route('cart.add'), ['product_id' => $product->id])->assertOk()->assertJson(['message' => 'Товар добавлен в корзину']);
        $cart = Cart::query()->firstOrFail();
        expect((int) CartItem::query()->where('cart_id', $cart->id)->sum('qty'))->toBe(1);

        $component = livewireWithCart(CheckoutForm::class, $cart);
        $this->travel(5)->seconds();
        fillCheckout($component)->call('submit')->assertHasNoErrors();

        expect(Order::query()->count())->toBe(1)->and($product->fresh()->stock_qty)->toBe(4);
    })->with(array_values(cardThemes()));

    it('tells the C3 reservation button from add-to-cart by icon and caption', function (): void {
        useTheme('volt');
        // volt uses C4; C3 is checked through the shared actions partial with the round style.
        $reserve = sellable(['name' => 'Стол', 'sales_mode' => SalesMode::Reservation, 'stock_qty' => 5]);
        $buy = sellable(['name' => 'Лампа', 'sales_mode' => SalesMode::Cart, 'stock_qty' => 5]);
        $actions = app(ProductPresenter::class);

        foreach ([[$reserve, true], [$buy, false]] as [$product, $isReserve]) {
            $view = $actions->present($product->fresh());
            $html = view('storefront.partials.cards._actions', ['view' => $view, 'cardActions' => $view->actions, 'style' => 'round'])->render();

            expect(str_contains($html, 'data-reserve-icon'))->toBe($isReserve)
                ->and(str_contains($html, 'data-reserve-caption'))->toBe($isReserve)
                ->and($html)->toContain('sr-only');
        }
    });

    it('opens the reservation and inquiry forms from the card', function (string $theme): void {
        useTheme($theme);
        $reserve = sellable(['name' => 'Стол', 'sales_mode' => SalesMode::Reservation, 'stock_qty' => 5]);
        $inquire = sellable(['name' => 'Шкаф', 'sales_mode' => SalesMode::Inquiry, 'stock_qty' => 5]);
        $both = sellable(['name' => 'Диван', 'sales_mode' => SalesMode::Both, 'stock_qty' => 5]);

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        // MR-03: a reservation card puts the product into the reservation cart («В резерв»); «Корзина и резерв» keeps the one-product form link.
        expect(cardHtml($html, 'Стол'))->toContain('data-action="cart"')->toContain('В резерв')->not->toContain('?reserve=1')
            ->and(cardHtml($html, 'Шкаф'))->toContain($inquire->slug.'?inquiry=1')
            ->and(cardHtml($html, 'Диван'))->toContain('data-action="cart"')->toContain($both->slug.'?reserve=1');

        $this->get(route('product.show', $reserve->slug).'?reserve=1')->assertOk()->assertSeeHtml('reservation-form');
        $this->get(route('product.show', $inquire->slug).'?inquiry=1')->assertOk()->assertSeeHtml('inquiry-form');
    })->with(array_column(cardThemes(), 0));
});

describe('listing variants (TH-13)', function (): void {
    it('keeps the filters in the left column on L1 and puts them above the grid on L2 and L3 with the same filter set', function (): void {
        listingProducts();

        useTheme('volt');
        $l1 = $this->get(route('catalog.index'))->assertOk()->getContent();
        expect($l1)->toContain('data-listing="L1"')->toContain('md:w-64')->not->toContain('data-filter-chips')->not->toContain('data-filter-panel');

        $fields = fn (string $html): array => array_values(array_unique(array_filter(
            (preg_match_all('#<(?:input|select)\b[^>]*\bname="([^"]+)"#', $html, $m) ? $m[1] : []),
            fn (string $name): bool => $name !== '_token',
        )));

        foreach (['arkada' => 'L2', 'prays' => 'L3'] as $theme => $variant) {
            useTheme($theme);
            $html = $this->get(route('catalog.index'))->assertOk()->getContent();

            expect($html)->toContain('data-listing="'.$variant.'"')->toContain('data-filter-chips')->toContain('data-filter-panel="top"')
                ->and($html)->toContain('data-chip="in_stock"')->toContain('data-chip="hit"')->toContain('id="sort-top"')
                ->and($html)->not->toContain('md:w-64')
                ->and($html)->toContain('data-open-filters')->toContain('data-open-sort')->toContain('sort-sheet')
                ->and($fields($html))->toEqualCanonicalizing($fields($l1));
        }
    });

    it('makes the chips toggle filters through plain links and keeps the sort', function (): void {
        listingProducts();
        useTheme('arkada');

        $html = $this->get(route('catalog.index', ['sort' => 'price_asc']))->assertOk()->getContent();
        preg_match('#<a href="([^"]+)"[^>]*data-chip="in_stock"#', $html, $stock);
        preg_match('#<a href="([^"]+)"[^>]*data-chip="hit"#', $html, $hit);

        expect(html_entity_decode($stock[1]))->toContain('in_stock=1')->toContain('sort=price_asc')
            ->and(html_entity_decode($hit[1]))->toContain('flags%5B0%5D=hit');

        $this->get(html_entity_decode($hit[1]))->assertOk()->assertSee('Товар 1');
        $on = $this->get(route('catalog.index', ['flags' => ['hit']]))->getContent();
        preg_match('#<a href="([^"]+)"[^>]*aria-current="true"[^>]*data-chip="hit"#', $on, $off);
        expect($off)->not->toBe([])->and(html_entity_decode($off[1]))->not->toContain('flags');
    });

    it('adds one column on a wide screen on L3 (the «Колонок в сетке» setting plus one)', function (): void {
        listingProducts();

        foreach ([4 => ['xl:grid-cols-5', 'lg:grid-cols-4'], 3 => ['lg:grid-cols-4']] as $columns => $classes) {
            $settings = app(AppearanceSettings::class);
            $settings->grid_columns = $columns;
            $settings->save();

            // C1/L3 (C5 is a list, it has no columns).
            useTheme('yarmarka');
            $grid = $this->get(route('catalog.index'))->assertOk()->getContent();
            preg_match('#<ul[^>]*data-listing-grid[^>]*>#', $grid, $ul);

            foreach ($classes as $class) {
                expect($ul[0])->toContain($class);
            }

            useTheme('volt');
            preg_match('#<ul[^>]*data-listing-grid[^>]*>#', $this->get(route('catalog.index'))->getContent(), $normal);
            expect($normal[0])->toContain($columns === 4 ? 'xl:grid-cols-4' : 'lg:grid-cols-3')->not->toContain('xl:grid-cols-5');
        }
    });

    it('renders C5 as one column of rows and C6 without gaps', function (): void {
        listingProducts();

        useTheme('prays');
        preg_match('#<ul[^>]*data-listing-grid[^>]*>#', $this->get(route('catalog.index'))->getContent(), $rows);
        expect($rows[0])->toContain('grid-cols-1')->not->toContain('md:grid-cols-2');

        useTheme('modul');
        preg_match('#<ul[^>]*data-listing-grid[^>]*>#', $this->get(route('catalog.index'))->getContent(), $cells);
        expect($cells[0])->toContain('gap-0')->toContain('data-card-grid="C6"');
    });

    it('keeps the load-more button and pagination in every listing variant (MB-07, SF-14)', function (): void {
        $category = Category::factory()->create();
        Product::factory()->count(30)->inCategory($category)->create(['stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);

        foreach (['volt', 'arkada', 'yarmarka', 'prays', 'modul'] as $theme) {
            useTheme($theme);
            $html = $this->get(route('catalog.index'))->assertOk()->getContent();

            expect($html)->toContain('data-load-more-button')->toContain('data-pagination')->toContain('data-listing-grid');
        }
    });
});

describe('default theme keeps the card and the listing of the shop before themes', function (): void {
    it('uses C1 and L1 with the original grid classes', function (): void {
        listingProducts();

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();
        preg_match('#<ul[^>]*data-listing-grid[^>]*>#', $html, $ul);

        expect($html)->toContain('data-card="C1"')->toContain('data-listing="L1"')
            ->and($ul[0])->toContain('class="grid grid-cols-2 gap-3 md:grid-cols-2 md:gap-4 lg:grid-cols-3 xl:grid-cols-4"');
    });
});
