<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Customers\CustomerAddressService;
use App\Settings\AppearanceSettings;
use App\Settings\CustomerSettings;
use App\Settings\GeneralSettings;
use Livewire\Livewire;

/**
 * MB-15 (TZ 27): HTTP markup tests of the phone layout. Layout rules that need a browser (scroll width, touch target
 * sizes) are checked by hand and with Playwright, see docs/PROGRESS.md.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

/** The part of the page between <body> and the footer of the bottom bar, as the bar is one <nav>. */
function bottomBar(string $html): string
{
    preg_match('#<nav[^>]*data-bottom-nav.*?</nav>#s', $html, $match);

    return $match[0] ?? '';
}

describe('viewport and safe areas (MB-01, MB-02)', function (): void {
    it('sets the viewport with viewport-fit=cover and never forbids zooming', function (): void {
        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">')
            ->and($html)->not->toContain('user-scalable')
            ->and($html)->not->toContain('maximum-scale');
    });

    it('defines the safe area variables the Telegram mode can override', function (): void {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('--shop-safe-bottom: env(safe-area-inset-bottom')
            ->and($css)->toContain('--shop-safe-top: env(safe-area-inset-top')
            ->and($css)->toContain('.shop-bottom-nav')
            ->and($css)->toContain('.shop-sticky-bar');
    });
});

describe('bottom navigation (MB-03)', function (): void {
    it('shows catalog, search, cart and sign-in with labels and marks the active item', function (): void {
        $bar = bottomBar($this->get(route('catalog.index'))->assertOk()->getContent());

        expect($bar)->toContain('data-bar-item="catalog"')
            ->and($bar)->toContain('data-bar-item="search"')
            ->and($bar)->toContain('data-bar-item="cart"')
            ->and($bar)->toContain('data-bar-item="account"')
            ->and($bar)->toContain('Каталог')->and($bar)->toContain('Поиск')->and($bar)->toContain('Корзина')->and($bar)->toContain('Войти')
            ->and($bar)->toContain('aria-label="Быстрая навигация"')
            ->and($bar)->toMatch('#data-bar-item="catalog"#')
            ->and(preg_match('#aria-current="page"[^>]*data-bar-item="catalog"#', $bar))->toBe(1);
    });

    it('puts the items in the order catalog, search, cart, cabinet', function (): void {
        $bar = bottomBar($this->get(route('home'))->getContent());

        $positions = array_map(fn (string $item): int|false => strpos($bar, 'data-bar-item="'.$item.'"'), ['catalog', 'search', 'cart', 'account']);

        expect($positions)->not->toContain(false)->and($positions)->toBe(collect($positions)->sort()->values()->all());
    });

    it('has no cart item when the shop has no cart (SF-01)', function (): void {
        sales(['default_mode' => SalesMode::Inquiry]);

        expect(bottomBar($this->get(route('home'))->getContent()))
            ->not->toContain('data-bar-item="cart"')
            ->toContain('data-bar-item="catalog"');
    });

    it('has no sign-in item when registration is off, but keeps the cabinet for a signed-in customer (CA-19)', function (): void {
        app(CustomerSettings::class)->fill(['registration_enabled' => false])->save();

        expect(bottomBar($this->get(route('home'))->getContent()))->not->toContain('data-bar-item="account"');

        signInCustomer($this, makeCustomer());
        $bar = bottomBar($this->get(route('home'))->getContent());

        expect($bar)->toContain('data-bar-item="account"')->and($bar)->toContain('Кабинет');
    });

    it('highlights the cabinet item on cabinet pages and the cart item on the cart page', function (): void {
        signInCustomer($this, makeCustomer());

        expect(preg_match('#aria-current="page"[^>]*data-bar-item="account"#', bottomBar($this->get(route('customer.orders'))->getContent())))->toBe(1);

        $bar = bottomBar($this->get(route('cart'))->getContent());

        expect(preg_match('#data-bar-item="cart"#', $bar))->toBe(1)
            ->and($bar)->toContain('aria-current="page"');
    });

    it('is not rendered on the checkout page and the sticky submit bar takes its place (MB-03, MB-08)', function (): void {
        $cart = cartWith(sellable(['sales_mode' => SalesMode::Cart]));

        $html = $this->withCookie('cart', $cart->token)->get(route('checkout'))->assertOk()->getContent();

        expect($html)->not->toContain('data-bottom-nav')
            ->and($html)->toContain('data-nav="off"')
            ->and($html)->toContain('data-sticky-checkout-bar')
            ->and($html)->toContain('data-sticky-submit');
    });

    it('is hidden by script while a text field has the focus (resources/js/app.js)', function (): void {
        $js = file_get_contents(resource_path('js/app.js'));

        expect($js)->toContain("root.dataset.kb = ''")->and($js)->toContain('focusin');
        expect(file_get_contents(resource_path('css/app.css')))->toContain('html[data-kb] .shop-bottom-nav');
    });
});

describe('product page on a phone (MB-04, MB-12)', function (): void {
    it('renders a sticky bar with the price and the main button of the sales mode', function (): void {
        $product = sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '1290.00']);

        $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();

        expect($html)->toContain('data-sticky-buy')
            ->and($html)->toContain('data-sticky-price')
            ->and($html)->toContain('data-sticky-action="buy"')
            ->and($html)->toContain('data-buy')
            ->and($html)->toContain('data-sticky')
            ->and($html)->toContain('В корзину');
    });

    it('offers a reservation or an inquiry as the main button for those modes', function (): void {
        $reserve = sellable(['sales_mode' => SalesMode::Reservation]);
        $inquire = sellable(['sales_mode' => SalesMode::Inquiry]);

        $this->get(route('product.show', $reserve->slug))->assertOk()->assertSee('data-sticky-action="buy"', false)->assertSee('Зарезервировать');
        $this->get(route('product.show', $inquire->slug))->assertOk()->assertSee('data-sticky-action="buy"', false)->assertSee('Оставить заявку');
    });

    it('turns the main button into a tel: link for a showcase product', function (): void {
        $settings = app(GeneralSettings::class);
        $settings->phones = ['+7 (900) 123-45-67'];
        $settings->save();
        $product = sellable(['sales_mode' => SalesMode::Showcase]);

        $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();

        expect($html)->toContain('data-sticky-action="call"')
            ->and($html)->toContain('href="tel:+79001234567"')
            ->and($html)->not->toContain('data-sticky-action="buy"');
    });

    it('shows «Описание» and «Характеристики» as folding sections on a phone and tabs from 768 px', function (): void {
        $product = sellable();

        $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();

        expect($html)->toContain('data-accordion="description"')
            ->and($html)->toContain('data-accordion="specs"')
            ->and($html)->toContain('role="tablist"')
            ->and($html)->toContain('aria-controls="panel-specs"');
    });

    it('lets the gallery close with the back gesture and shows position dots', function (): void {
        $js = file_get_contents(resource_path('js/app.js'));

        expect($js)->toContain("Alpine.directive('overlay'")->and($js)->toContain('window.history.pushState');
    });

    it('opens the reservation and inquiry dialogs as sheets that close with the back gesture', function (): void {
        $product = sellable(['sales_mode' => SalesMode::Inquiry]);

        $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();

        expect($html)->toContain('x-overlay="open"')
            ->and($html)->toContain('x-trap.noscroll.inert="open"')
            ->and($html)->toContain('rounded-t-2xl');
    });
});

describe('forms (MB-06)', function (): void {
    it('marks the contact fields of the checkout with type, inputmode and autocomplete', function (): void {
        $cart = cartWith(sellable(['sales_mode' => SalesMode::Cart]));

        $html = $this->withCookie('cart', $cart->token)->get(route('checkout'))->assertOk()->getContent();

        expect($html)->toMatch('#id="co-name"[^>]*autocomplete="name"#')
            ->and($html)->toMatch('#id="co-phone"[^>]*type="tel"#')
            ->and($html)->toMatch('#type="tel"[^>]*autocomplete="tel"#')
            ->and($html)->toMatch('#id="co-email"[^>]*type="email"#')
            ->and($html)->toMatch('#type="email"[^>]*autocomplete="email"#')
            ->and($html)->toMatch('#autocomplete="street-address"#')
            ->and($html)->toContain('inputmode="tel"');
    });

    it('marks the reservation and inquiry forms of the product page', function (): void {
        $reserve = sellable(['sales_mode' => SalesMode::Reservation]);
        $inquire = sellable(['sales_mode' => SalesMode::Inquiry]);

        $reservation = $this->get(route('product.show', $reserve->slug))->assertOk()->getContent();
        $inquiry = $this->get(route('product.show', $inquire->slug))->assertOk()->getContent();

        expect($reservation)->toMatch('#id="rs-qty"[^>]*inputmode="numeric"#')
            ->and($reservation)->toMatch('#id="rs-name"[^>]*autocomplete="name"#')
            ->and($reservation)->toMatch('#id="rs-phone"[^>]*type="tel"#')
            ->and($reservation)->toMatch('#id="rs-email"[^>]*type="email"#')
            ->and($inquiry)->toMatch('#id="iq-phone"[^>]*type="tel"#')
            ->and($inquiry)->toMatch('#id="iq-email"[^>]*type="email"#')
            ->and($inquiry)->toMatch('#id="iq-qty"[^>]*inputmode="numeric"#');
    });

    it('marks the sign-in, registration and code forms', function (): void {
        $login = $this->get(route('customer.login'))->assertOk()->getContent();
        $register = $this->get(route('customer.register'))->assertOk()->getContent();

        expect($login)->toMatch('#id="lg-email"[^>]*type="email"#')
            ->and($login)->toContain('autocomplete="current-password"')
            ->and($register)->toMatch('#id="rg-phone"[^>]*type="tel"#')
            ->and($register)->toContain('autocomplete="new-password"')
            ->and($register)->toContain('autocomplete="name"');

        $customer = makeCustomer();
        signInCustomer($this, $customer);

        $this->get(route('customer.profile'))->assertOk()
            ->assertSee('autocomplete="tel"', false)->assertSee('inputmode="tel"', false);
        $this->get(route('customer.addresses'))->assertOk()->assertSee('autocomplete="street-address"', false);
        $this->get(route('customer.company'))->assertOk()
            ->assertSee('id="cm-inn"', false)->assertSee('inputmode="numeric"', false)->assertSee('autocomplete="organization"', false);
        $this->get(route('customer.wholesale'))->assertOk()
            ->assertSee('name="inn"', false)->assertSee('inputmode="numeric"', false);
    });

    it('marks a code field as a one-time code', function (): void {
        // The same markup serves registration, sign-in, reset, e-mail and account deletion codes.
        $blade = file_get_contents(resource_path('views/storefront/account/confirm.blade.php'));

        expect($blade)->toContain('inputmode="numeric"')->and($blade)->toContain('autocomplete="one-time-code"');
    });

    it('keeps the field font at 16 px and the targets at 44 px on a phone', function (): void {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain('font-size: 16px')->and($css)->toContain('min-height: 2.75rem');
    });

    it('puts the error under the field of a server-rendered form', function (): void {
        signInCustomer($this, makeCustomer());

        $this->from(route('customer.profile'))->post(route('customer.profile.update'), ['name' => '', 'phone' => ''])
            ->assertRedirect(route('customer.profile'));

        $html = $this->get(route('customer.profile'))->assertOk()->getContent();

        expect($html)->toContain('data-field-error-text')
            ->and($html)->toMatch('#id="pf-name"[^>]*aria-invalid="true"#');
    });
});

describe('listing (MB-07, MB-11)', function (): void {
    it('defaults to two columns on a phone and offers 1 or 2 in the appearance settings', function (): void {
        expect(app(AppearanceSettings::class)->mobile_columns)->toBe(2);

        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create();

        $this->get(route('catalog.category', $category->slug))->assertOk()
            ->assertSee('data-listing-grid', false)
            ->assertSee('grid-cols-2', false)
            ->assertDontSee('grid-cols-1 min-[30rem]:grid-cols-2', false);
    });

    it('renders one column on a phone when the shop asks for it', function (): void {
        $appearance = app(AppearanceSettings::class);
        $appearance->mobile_columns = 1;
        $appearance->save();
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create();

        $this->get(route('catalog.category', $category->slug))->assertOk()
            ->assertSee('grid-cols-1 min-[30rem]:grid-cols-2', false);
    });

    it('sizes the card photo for the column width, 400 px at most on a phone', function (): void {
        $category = Category::factory()->create();
        $product = Product::factory()->inCategory($category)->create();
        ProductImage::factory()->create(['product_id' => $product->id, 'width' => 800, 'height' => 600]);

        $html = $this->get(route('catalog.category', $category->slug))->assertOk()->getContent();

        expect($html)->toContain('sizes="(min-width: 1280px) 20vw, (min-width: 768px) 30vw, min(400px, calc(50vw - 1.5rem))"');
    });

    it('offers filters and sorting on a phone as a slide-over and a bottom sheet', function (): void {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create();

        $html = $this->get(route('catalog.category', $category->slug).'?sort=name')->assertOk()->getContent();

        expect($html)->toContain('data-open-filters')
            ->and($html)->toContain('data-open-sort')
            ->and($html)->toContain('id="sort-sheet"')
            ->and($html)->toContain('x-overlay="sortOpen"')
            ->and($html)->toContain('x-overlay="filtersOpen"')
            ->and($html)->toContain('data-sort-option="price_asc"')
            // The links keep the other parameters of the address and drop the page.
            ->and($html)->toContain('sort=price_asc');
    });

    it('has «Показать ещё» that points to the next page while the classic pagination stays in the markup', function (): void {
        $appearance = app(AppearanceSettings::class);
        $appearance->per_page = 4;
        $appearance->save();
        $category = Category::factory()->create();
        Product::factory()->count(6)->inCategory($category)->create();

        $html = $this->get(route('catalog.category', $category->slug))->assertOk()->getContent();
        $next = e(route('catalog.category', $category->slug).'?page=2');

        expect($html)->toContain('data-load-more')
            ->and($html)->toContain('data-next="'.$next.'"')
            ->and($html)->toContain('data-load-more-button')
            ->and($html)->toContain('Показать ещё')
            // Robots and visitors without JavaScript: the numbered pagination and rel="next".
            ->and($html)->toContain('data-pagination')
            ->and($html)->toContain('rel="next"')
            ->and($html)->toContain('href="'.$next.'"');

        $last = $this->get(route('catalog.category', $category->slug).'?page=2')->assertOk()->getContent();

        expect($last)->not->toContain('data-load-more-button')->and($last)->toContain('rel="prev"');
    });

    it('serves the next page as an ordinary page the script can read the cards from', function (): void {
        $appearance = app(AppearanceSettings::class);
        $appearance->per_page = 4;
        $appearance->save();
        $category = Category::factory()->create();
        Product::factory()->count(6)->inCategory($category)->create();

        $html = $this->get(route('catalog.category', $category->slug).'?page=2')->assertOk()->getContent();

        expect(substr_count($html, '<article'))->toBe(2)->and($html)->toContain('data-listing-grid');
    });
});

describe('appearance setting (MB-07)', function (): void {
    it('saves «Колонок на телефоне» from the admin page', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageAppearanceSettings::class)
            ->assertSee('Колонок на телефоне')
            ->fillForm(['mobile_columns' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppearanceSettings::class)->mobile_columns)->toBe(1);
    });
});

describe('cart and checkout (MB-08)', function (): void {
    it('shows the total and the checkout button in a sticky bar of the cart', function (): void {
        $cart = cartWith(sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '500.00']));

        $html = $this->withCookie('cart', $cart->token)->get(route('cart'))->assertOk()->getContent();

        expect($html)->toContain('data-sticky-cart')
            ->and($html)->toContain('data-sticky-total')
            ->and($html)->toContain('data-sticky-checkout')
            ->and($html)->toContain('data-sticky');
    });

    it('folds «Доставка» and «Комментарий» and keeps the choice of a saved address in a bottom sheet', function (): void {
        $customer = makeCustomer();
        app(CustomerAddressService::class)->create($customer, [
            'label' => 'Дом', 'recipient_name' => 'Пётр', 'phone' => '+79001234567', 'address' => 'Москва, ул. Тверская, 1', 'is_default' => true,
        ]);
        signInCustomer($this, $customer);
        cartWith(sellable(['sales_mode' => SalesMode::Cart]))->forceFill(['customer_id' => $customer->id])->save();

        $html = $this->get(route('checkout'))->assertOk()->getContent();

        expect($html)->toContain('aria-controls="co-delivery"')
            ->and($html)->toContain('aria-controls="co-comment"')
            ->and($html)->toContain('data-collapse')
            ->and($html)->toContain('id="co-address-sheet"')
            ->and($html)->toContain('data-address-option=');
    });
});

describe('cabinet (MB-09)', function (): void {
    it('lists orders as cards with the number, date, status badge, sum and «Открыть»', function (): void {
        $customer = makeCustomer();
        Order::factory()->create(['customer_id' => $customer->id]);
        signInCustomer($this, $customer);

        $html = $this->get(route('customer.orders'))->assertOk()->getContent();

        expect($html)->toContain('data-order-card')
            ->and($html)->toContain('data-order-status-badge')
            ->and($html)->toContain('data-order-open')
            ->and($html)->toContain('Открыть')
            // History filters: a slide-over on a phone.
            ->and($html)->toContain('data-open-filters')
            ->and($html)->toContain('x-overlay="filtersOpen"');
    });
});

describe('contacts (MB-10)', function (): void {
    it('shows the phone as a tel: link in the mobile menu', function (): void {
        $settings = app(GeneralSettings::class);
        $settings->phones = ['+7 (900) 123-45-67'];
        $settings->save();

        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('data-mobile-contacts')->and($html)->toContain('data-contact="phone"')->and($html)->toContain('href="tel:+79001234567"');
    });

    it('shows the Telegram and WhatsApp buttons only when they are configured', function (): void {
        $plain = $this->get(route('home'))->assertOk()->getContent();

        expect($plain)->not->toContain('data-contact="telegram"')->and($plain)->not->toContain('data-contact="whatsapp"');

        config(['shop.telegram_bot_username' => 'shop_helper_bot']);
        $settings = app(GeneralSettings::class);
        $settings->social_links = [['title' => 'WhatsApp', 'url' => 'https://wa.me/79001234567']];
        $settings->phones = ['+7 (900) 123-45-67'];
        $settings->save();

        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('data-contact="telegram"')
            ->and($html)->toContain('href="https://t.me/shop_helper_bot"')
            ->and($html)->toContain('Написать в Telegram')
            ->and($html)->toContain('data-contact="whatsapp"')
            ->and($html)->toContain('href="https://wa.me/79001234567"');
    });

    it('ignores a malformed bot username', function (): void {
        config(['shop.telegram_bot_username' => 'bad name!']);

        $this->get(route('home'))->assertOk()->assertDontSee('data-contact="telegram"', false);
    });
});
