<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\SalesMode;
use App\Enums\SalesModeSource;
use App\Enums\StockDisplay;
use App\Enums\StorefrontSection;
use App\Exceptions\CartChangedException;
use App\Exceptions\SalesException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Services\Access\CatalogAccess;
use App\Services\Access\CustomerGroupService;
use App\Services\Cart\CartService;
use App\Services\Catalog\CatalogVersion;
use App\Services\Home\HomePageRenderer;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderService;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\CheckoutMethods;
use App\Services\Sales\MethodCodes;
use App\Services\Sales\SalesModeResolver;
use App\Settings\SalesSettings;

/**
 * CG-06, CG-07, CG-08, CG-09, CG-14, CG-27 (TZ 25.6, 25.9): the sales mode is clamped by the group, hidden prices
 * lower it, and the server re-checks delivery, payment and the order minimum.
 */
describe('sales mode clamp', function (): void {
    it('narrows «both» to what the group allows (CG-07)', function (array $allowed, SalesMode $expected): void {
        $access = app(CatalogAccess::class)->for(CustomerGroup::factory()->salesModes($allowed)->create());
        $resolver = app(SalesModeResolver::class);

        $resolved = $resolver->resolveFor(SalesMode::Both, null, false, $access);

        expect($resolved->mode)->toBe($expected);
    })->with([
        'cart and reservation' => [['cart', 'reservation'], SalesMode::Both],
        'cart only' => [['cart', 'inquiry'], SalesMode::Cart],
        'reservation only' => [['reservation'], SalesMode::Reservation],
        'inquiry only' => [['inquiry'], SalesMode::Inquiry],
        'nothing' => [[], SalesMode::Showcase],
    ]);

    it('turns an unavailable cart or reservation into an inquiry, else a showcase (CG-07)', function (): void {
        $resolver = app(SalesModeResolver::class);
        $withInquiry = app(CatalogAccess::class)->for(CustomerGroup::factory()->salesModes(['inquiry'])->create());
        $without = app(CatalogAccess::class)->for(CustomerGroup::factory()->salesModes([])->create());

        expect($resolver->resolveFor(SalesMode::Cart, null, false, $withInquiry)->mode)->toBe(SalesMode::Inquiry)
            ->and($resolver->resolveFor(SalesMode::Reservation, null, false, $withInquiry)->mode)->toBe(SalesMode::Inquiry)
            ->and($resolver->resolveFor(SalesMode::Cart, null, false, $without)->mode)->toBe(SalesMode::Showcase)
            ->and($resolver->resolveFor(SalesMode::Inquiry, null, false, $without)->mode)->toBe(SalesMode::Showcase);
    });

    it('reports the group as the source of a clamped mode and leaves others alone (CG-07)', function (): void {
        $resolver = app(SalesModeResolver::class);
        $access = app(CatalogAccess::class)->for(CustomerGroup::factory()->salesModes(['inquiry'])->create());

        expect($resolver->resolveFor(SalesMode::Cart, null, false, $access)->source)->toBe(SalesModeSource::Group)
            ->and($resolver->resolveFor(SalesMode::Inquiry, null, false, $access)->source)->toBe(SalesModeSource::Product)
            ->and($resolver->resolveFor(SalesMode::Cart, null, false)->mode)->toBe(SalesMode::Cart);
    });

    it('lowers cart and reservation to an inquiry when prices are hidden, or a showcase without inquiries (CG-06)', function (): void {
        $resolver = app(SalesModeResolver::class);
        $hidden = app(CatalogAccess::class)->for(CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Request)->create());
        $noInquiry = app(CatalogAccess::class)->for(CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Login)->salesModes(['cart'])->create());

        expect($resolver->resolveFor(SalesMode::Both, null, false, $hidden)->mode)->toBe(SalesMode::Inquiry)
            ->and($resolver->resolveFor(SalesMode::Cart, null, false, $noInquiry)->mode)->toBe(SalesMode::Showcase);
    });

    it('applies the guest clamp to the storefront actions and to the cart (CG-07, CG-14)', function (): void {
        $product = sellable();
        configureGuest(['allowed_sales_modes' => ['inquiry']]);
        $cart = cartWith($product);

        expect(app(AvailabilityService::class)->actionsFor($product)->canAddToCart)->toBeFalse()
            ->and(app(AvailabilityService::class)->actionsFor($product)->canInquire)->toBeTrue()
            ->and(fn () => app(CartService::class)->add($cart, $product))->toThrow(SalesException::class);
        expect(fn () => app(OrderService::class)->createFromCart($cart, checkoutData()))->toThrow(CartChangedException::class);
        expect(Order::query()->count())->toBe(0);
    });

    it('rejects a reservation and keeps an inquiry for a group without prices (CG-06)', function (): void {
        $product = sellable();
        configureGuest(['price_display' => PriceDisplayMode::Request]);

        expect(fn () => app(OrderService::class)->createReservation($product, reservationData()))->toThrow(SalesException::class);

        $order = app(OrderService::class)->createInquiry($product, new InquiryData(1, 'Иван', '+7 900 123-45-67', null, null, true, '127.0.0.1', 'Pest'));
        expect($order->total)->toBeNull();
    });

    it('does not clamp the admin view of the effective mode (CG-07)', function (): void {
        $product = sellable(['sales_mode' => SalesMode::Cart]);
        configureGuest(['allowed_sales_modes' => []]);

        expect(app(SalesModeResolver::class)->resolve($product)->mode)->toBe(SalesMode::Cart);
    });
});

describe('server-side checkout rules', function (): void {
    it('refuses a delivery or payment method the group does not allow (CG-09, CG-14)', function (): void {
        $product = sellable();
        $cart = cartWith($product);
        configureGuest(['allowed_delivery_codes' => ['kurerskaia-dostavka'], 'allowed_payment_codes' => ['kartoi-pri-polucenii']]);

        expect(array_map(fn ($o) => $o->key, app(CheckoutMethods::class)->deliveries()))->toBe(['kurerskaia-dostavka'])
            ->and(array_map(fn ($o) => $o->key, app(CheckoutMethods::class)->payments()))->toBe(['kartoi-pri-polucenii']);

        expect(fn () => app(OrderService::class)->createFromCart($cart, checkoutData('samovyvoz')))
            ->toThrow(SalesException::class, __('sales.errors.delivery_invalid'));

        $data = checkoutData('kurerskaia-dostavka');
        expect(fn () => app(OrderService::class)->createFromCart($cart, $data))
            ->toThrow(SalesException::class, __('sales.errors.payment_invalid'));
        expect(Order::query()->count())->toBe(0);
    });

    it('accepts the allowed methods (CG-09)', function (): void {
        $cart = cartWith(sellable());
        configureGuest(['allowed_delivery_codes' => ['samovyvoz'], 'allowed_payment_codes' => ['nalicnymi-pri-polucenii']]);

        expect(app(OrderService::class)->createFromCart($cart, checkoutData('samovyvoz'))->exists)->toBeTrue();
    });

    it('uses the group minimum instead of the shop one and shows it in the cart (CG-08)', function (): void {
        sales(['min_order_amount' => 0]);
        $cart = cartWith(sellable(['base_price' => '1000.00']));
        configureGuest(['min_order_amount' => '5000.00']);

        expect(app(CartService::class)->summary($cart)->minOrderAmount)->toBe(500000)
            ->and(app(CartService::class)->summary($cart)->canCheckout)->toBeFalse();
        expect(fn () => app(OrderService::class)->createFromCart($cart, checkoutData()))->toThrow(SalesException::class);

        configureGuest(['min_order_amount' => '500.00']);
        expect(app(OrderService::class)->createFromCart($cart, checkoutData())->exists)->toBeTrue();
    });

    it('falls back to the shop minimum when the group has none (CG-08)', function (): void {
        sales(['min_order_amount' => 300000]);
        $cart = cartWith(sellable(['base_price' => '1000.00']));

        expect(app(CartService::class)->summary($cart)->minOrderAmount)->toBe(300000);
    });

    it('removes cart lines of a category the group may no longer see (CG-14)', function (): void {
        $product = sellable();
        $cart = cartWith($product);
        configureGuest([], CategoryAccessMode::AllExcept, [$product->category]);

        expect(fn () => app(OrderService::class)->createFromCart($cart, checkoutData()))->toThrow(CartChangedException::class);
        expect($cart->items()->count())->toBe(0);
    });

    it('does not let a hidden product into the cart, a reservation or an inquiry (CG-14)', function (): void {
        $product = sellable();
        $cart = Cart::factory()->create();
        configureGuest([], CategoryAccessMode::AllExcept, [$product->category]);

        expect(fn () => app(CartService::class)->add($cart, $product))->toThrow(SalesException::class)
            ->and(fn () => app(OrderService::class)->createReservation($product, reservationData()))->toThrow(SalesException::class);
    });

    it('rejects the add-to-cart form for a product of a hidden category (CG-14)', function (): void {
        $product = sellable();
        configureGuest([], CategoryAccessMode::AllExcept, [$product->category]);

        $this->postJson('/cart/add', ['product_id' => $product->id])->assertStatus(422);
        expect(CartItem::query()->count())->toBe(0);
    });

    it('refuses the catalog section to cart operations when it is hidden (CG-04)', function (): void {
        $product = sellable();
        $cart = Cart::factory()->create();
        configureGuest(['visible_sections' => array_values(array_diff(StorefrontSection::defaults(), ['catalog']))]);

        expect(fn () => app(CartService::class)->add($cart, $product))->toThrow(SalesException::class);
    });
});

describe('stock display', function (): void {
    it('shows the stock by the group setting and by the shop setting for «inherit» (CG-27)', function (GroupStockDisplay $display, string $expected): void {
        sales(['stock_tracking' => true, 'stock_display' => StockDisplay::Presence, 'low_stock_threshold' => 3]);
        $product = sellable(['stock_qty' => 2]);
        configureGuest(['stock_display' => $display]);

        $this->get('/product/'.$product->slug)->assertOk()->assertSee($expected);
    })->with([
        'exact' => [GroupStockDisplay::Exact, '2 шт'],
        'low' => [GroupStockDisplay::Low, 'Мало'],
        'yes/no' => [GroupStockDisplay::YesNo, 'В наличии'],
        'inherit (presence)' => [GroupStockDisplay::Inherit, 'В наличии'],
    ]);

    it('follows the shop setting for «inherit» (CG-27)', function (): void {
        sales(['stock_tracking' => true, 'stock_display' => StockDisplay::Exact]);
        $product = sellable(['stock_qty' => 7]);

        $this->get('/product/'.$product->slug)->assertSee('7 шт');
    });
});

describe('sales method codes', function (): void {
    it('gives every delivery and payment method a stable code (CG-09)', function (): void {
        $settings = app(SalesSettings::class);

        expect(array_column($settings->delivery_methods, 'code'))->toBe(['samovyvoz', 'kurerskaia-dostavka'])
            ->and(array_column($settings->payment_methods, 'code'))->toBe(['nalicnymi-pri-polucenii', 'kartoi-pri-polucenii']);
    });

    it('generates unique codes for new rows and keeps existing ones after a rename (CG-09)', function (): void {
        $rows = MethodCodes::ensure([
            ['name' => 'Новое имя', 'code' => 'samovyvoz'],
            ['name' => 'Почта'],
            ['name' => 'Почта'],
            ['name' => 'Копия', 'code' => 'samovyvoz'],
        ]);

        expect(array_column($rows, 'code'))->toBe(['samovyvoz', 'pocta', 'pocta-2', 'kopiia']);
    });
});

describe('cache keys', function (): void {
    it('keys the home page by group and catalog version (CG-19)', function (): void {
        $keyBefore = HomePageRenderer::cacheKey();
        $this->get('/')->assertOk();

        expect($keyBefore)->toContain('home:'.guestGroup()->id.':')
            ->and(Cache::has($keyBefore))->toBeTrue();

        configureGuest(['name' => 'Гость']);
        expect(HomePageRenderer::cacheKey())->not->toBe($keyBefore);

        $other = CustomerGroup::factory()->create();
        previewAs($this, $other);
        expect(HomePageRenderer::cacheKey())->toContain('home:'.$other->id.':');
    });

    it('serves each group its own home page from the cache (CG-19)', function (): void {
        $category = Category::factory()->create();
        $hiddenCategory = Category::factory()->create();
        Product::factory()->inCategory($category)->featured()->create(['name' => 'Хит открытый']);
        Product::factory()->inCategory($hiddenCategory)->featured()->create(['name' => 'Хит скрытый']);
        $limited = CustomerGroup::factory()->categoryAccess(CategoryAccessMode::Only, [$category])->create();

        $this->get('/')->assertSee('Хит скрытый');

        previewAs($this, $limited);
        $this->get('/')->assertSee('Хит открытый')->assertDontSee('Хит скрытый');
    });

    it('moves the catalog version on when a group, its categories or a product change (CG-19)', function (): void {
        $first = CatalogVersion::current();
        $group = CustomerGroup::factory()->create();
        $afterGroup = CatalogVersion::current();
        app(CustomerGroupService::class)->save($group, ['name' => 'Другое имя']);
        $afterSave = CatalogVersion::current();
        Product::factory()->inCategory(Category::factory()->create())->create();
        $afterProduct = CatalogVersion::current();

        expect([$first, $afterGroup, $afterSave, $afterProduct])->each->toBeString()
            ->and(count(array_unique([$first, $afterGroup, $afterSave, $afterProduct])))->toBe(4);
    });

    it('rebuilds the access set after the selection changes (CG-19)', function (): void {
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $group = CustomerGroup::factory()->categoryAccess(CategoryAccessMode::Only, [$a])->create();

        expect(app(CatalogAccess::class)->for($group)->canSeeProductsIn($b->id))->toBeFalse();

        app(CustomerGroupService::class)->save($group, [], [$a->id, $b->id]);

        expect(app(CatalogAccess::class)->for($group)->canSeeProductsIn($b->id))->toBeTrue();
    });
});
