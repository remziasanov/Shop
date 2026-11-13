<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Category;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoLink;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

const BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

function clicks(PromoLink $link): array
{
    $row = DB::table('promo_link_stats')->where('promo_link_id', $link->id)->selectRaw('COALESCE(SUM(clicks),0) as c, COALESCE(SUM(unique_clicks),0) as u')->first();

    return [(int) $row->c, (int) $row->u];
}

it('redirects with 302 to each kind of target with the UTM labels (PM-21, PM-22, PM-27)', function (): void {
    $product = sellable(['slug' => 'kurtka']);
    $size = optionWith('Размер', ['S']);
    $card = variantCard(['slug' => 'platie'], $size);
    $variant = variantOf($card, [$size->values[0]], ['stock_qty' => 3]);
    $category = Category::factory()->create(['slug' => 'obuv']);

    $cases = [
        [PromoLink::factory()->toProduct($product)->create(['utm_source' => 'tg', 'utm_campaign' => 'spring']), '/product/kurtka?utm_source=tg&utm_campaign=spring'],
        [PromoLink::factory()->toVariant($variant)->create(), '/product/platie?variant='.$variant->id],
        [PromoLink::factory()->toCategory($category)->create(), '/catalog/obuv'],
        [PromoLink::factory()->toCatalogFilter('/catalog/obuv?sort=price_asc')->create(), '/catalog/obuv?sort=price_asc'],
        [PromoLink::factory()->toUrl('/page/delivery')->create(['utm_medium' => 'post']), '/page/delivery?utm_medium=post'],
    ];

    foreach ($cases as [$link, $expected]) {
        $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug)
            ->assertStatus(302)->assertRedirect(url($expected))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
});

it('sets the attribution and promo cookies for 30 days, HttpOnly and SameSite=Lax (PM-23, PM-24)', function (): void {
    $code = PromoCode::factory()->create(['code' => 'LINK10']);
    $link = promoLink(['promo_code_id' => $code->id]);

    $response = $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug)->assertRedirect();

    foreach (['shop_pl' => (string) $link->id, 'shop_promo' => 'LINK10'] as $name => $value) {
        $cookie = $response->getCookie($name);
        expect($cookie)->not->toBeNull()
            ->and($cookie->getValue())->toBe($value)
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax')
            ->and($cookie->getPath())->toBe('/')
            ->and(abs($cookie->getExpiresTime() - now()->addDays(30)->getTimestamp()))->toBeLessThan(5);
    }
});

it('sets the secure flag when the connection is secure', function (): void {
    $link = promoLink();

    $cookie = $this->withHeader('User-Agent', BROWSER_UA)->get('https://localhost/go/'.$link->slug)->getCookie('shop_pl');

    expect($cookie?->isSecure())->toBeTrue();
});

it('sets no promo code cookie for a link without a code', function (): void {
    $response = $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.promoLink()->slug);

    expect($response->getCookie('shop_promo'))->toBeNull();
});

it('counts clicks, unique clicks per browser, and ignores bots (PM-25)', function (): void {
    $link = promoLink();
    $other = promoLink();

    $first = $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug);
    expect(clicks($link))->toBe([1, 1]);

    // The same browser (it sends the cookie back) comes again: a click, not a unique one.
    $seen = $first->getCookie('shop_pl_seen');
    expect($seen)->not->toBeNull();
    $this->withCookie('shop_pl_seen', $seen->getValue())->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug);
    expect(clicks($link))->toBe([2, 1]);

    // Another link is unique for this browser on its own.
    $this->withCookie('shop_pl_seen', $seen->getValue())->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$other->slug);
    expect(clicks($other))->toBe([1, 1]);

    // A browser without the cookie is a new visitor.
    $this->defaultCookies = [];
    $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug);
    expect(clicks($link))->toBe([3, 2]);

    foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'facebookexternalhit/1.1', ''] as $agent) {
        $this->withHeader('User-Agent', $agent)->get('/go/'.$link->slug)->assertStatus(302);
    }

    expect(clicks($link))->toBe([3, 2]);
});

it('sends an unusable link to the catalog with the «Ссылка устарела» notice and no cookies (PM-22)', function (): void {
    $product = sellable(['slug' => 'hidden', 'is_active' => false]);

    $links = [
        promoLink(['is_active' => false]),
        promoLink(['ends_at' => now()->subDay()]),
        promoLink(['starts_at' => now()->addDay()]),
        PromoLink::factory()->toProduct($product)->create(),
    ];

    foreach ($links as $link) {
        $response = $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug)
            ->assertStatus(302)->assertRedirect(route('catalog.index'))
            ->assertSessionHas('promo_link_notice', 'Ссылка устарела')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        expect($response->getCookie('shop_pl'))->toBeNull();
        expect(clicks($link))->toBe([0, 0]);
    }

    $this->withHeader('User-Agent', BROWSER_UA)->get('/go/no-such-link')->assertRedirect(route('catalog.index'))
        ->assertSessionHas('promo_link_notice', 'Ссылка устарела');
});

it('shows the notice on the catalog after the redirect', function (): void {
    $this->withHeader('User-Agent', BROWSER_UA)->followingRedirects()->get('/go/no-such-link')->assertOk()->assertSee('Ссылка устарела');
});

it('never redirects off-site, whatever the stored target is (PM-22)', function (): void {
    $targets = ['//evil.example', '/\\evil.example', 'https://evil.example/x', 'http://evil.example', 'javascript:alert(1)', '///evil.example', "/ok\r\nLocation: http://evil.example"];

    foreach ($targets as $target) {
        $link = PromoLink::factory()->toUrl($target)->create();
        $response = $this->withHeader('User-Agent', BROWSER_UA)->get('/go/'.$link->slug)->assertStatus(302);

        expect($response->headers->get('Location'))->toBe(route('catalog.index'), $target);
    }
});

it('rejects slugs with odd characters', function (): void {
    $this->get('/go/a_b')->assertNotFound();
    $this->get('/go/'.str_repeat('a', 65))->assertNotFound();
});

it('lists /go/ in robots.txt (PM-22)', function (): void {
    expect($this->get('/robots.txt')->assertOk()->getContent())->toContain("Disallow: /go/\n");
});

it('attributes the order of the checkout to the link of the cookie, with and without a code (PM-24)', function (): void {
    $link = promoLink();
    $cart = promoCart(sellable());
    Livewire::withCookie('shop_pl', (string) $link->id);

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component)->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();
    expect($order->promo_link_id)->toBe($link->id)->and($order->promo_code_id)->toBeNull();
});

it('ignores a forged link cookie at checkout (PM-24)', function (): void {
    $cart = promoCart(sellable());
    Livewire::withCookie('shop_pl', '999999');

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component)->call('submit')->assertHasNoErrors();
    expect(Order::query()->firstOrFail()->promo_link_id)->toBeNull();

    Livewire::withCookie('shop_pl', 'abc');
    $cart2 = promoCart(sellable());
    $component = livewireWithCart(CheckoutForm::class, $cart2);
    $this->travel(5)->seconds();
    fillCheckout($component)->call('submit')->assertHasNoErrors();
    expect(Order::query()->latest('id')->firstOrFail()->promo_link_id)->toBeNull();
});

it('attributes reservations and inquiries to the link, without a promo code (PM-07, PM-24)', function (): void {
    $link = promoLink();
    $code = PromoCode::factory()->create(['code' => 'LINK10']);
    $product = sellable(['stock_qty' => 5]);
    $inquiryProduct = sellable(['sales_mode' => SalesMode::Inquiry, 'price_on_request' => true]);
    Livewire::withCookie('shop_pl', (string) $link->id)->withCookie('shop_promo', 'LINK10');

    $reservation = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id]);
    $inquiry = livewireWithCart(InquiryForm::class, null, ['productId' => $inquiryProduct->id]);
    $this->travel(5)->seconds();

    foreach ([$reservation, $inquiry] as $component) {
        foreach (['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'consent' => true, 'qty' => 1] as $key => $value) {
            $component->set($key, $value);
        }

        $component->call('submit')->assertHasNoErrors();
    }

    $orders = Order::query()->whereIn('type', [OrderType::Reservation, OrderType::Inquiry])->get();

    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('promo_link_id')->unique()->all())->toBe([$link->id])
        ->and($orders->pluck('promo_code_id')->filter()->all())->toBe([])
        ->and($orders->every(fn (Order $o): bool => $o->promo_discount === '0.00'))->toBeTrue()
        ->and($code->refresh()->usage_count)->toBe(0);
});
