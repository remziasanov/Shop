<?php

declare(strict_types=1);

use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Order;
use App\Models\PromoCode;
use App\Services\Promo\PromoAttemptGuard;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    Cache::flush();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->product = sellable(['name' => 'Куртка', 'base_price' => '1000.00', 'stock_qty' => 10]);
});

it('shows the promo box and applies, normalizes and removes a code on the cart page (PM-13)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'SPRING10']);
    $cart = promoCart($this->product, 3);

    $page = livewireWithCart(CartPage::class, $cart)
        ->assertSee('Есть промокод?')
        ->set('promoInput', '  spring10 ')->call('applyPromo')
        ->assertSet('promoError', null)
        ->assertSee('Промокод SPRING10 применён')
        ->assertSee('Скидка по промокоду SPRING10')
        ->assertSeeHtml('data-line-old')
        ->assertSeeHtml('data-promo-total');

    expect($cart->refresh()->promo_code)->toBe('SPRING10')->and($cart->promo_applied_manually)->toBeTrue();
    $page->assertSee(Money::format(270000));

    $page->call('removePromo')->assertDontSee('Скидка по промокоду')->assertDontSeeHtml('data-line-old');
    expect($cart->refresh()->promo_code)->toBeNull();
});

it('shows the refusal message of the code without revealing more (PM-09)', function (): void {
    PromoCode::factory()->create(['code' => 'BIGORDER', 'min_order_amount' => '5000.00']);
    $cart = promoCart($this->product, 1);

    livewireWithCart(CartPage::class, $cart)
        ->set('promoInput', 'NOPE-CODE')->call('applyPromo')->assertSet('promoError', 'Промокод не найден')
        ->set('promoInput', 'bigorder')->call('applyPromo')->assertSee('Минимальная сумма заказа для кода');

    expect($cart->refresh()->promo_code)->toBeNull();
});

it('asks to type a code when the input is empty', function (): void {
    livewireWithCart(CartPage::class, promoCart($this->product))->call('applyPromo')->assertSet('promoError', 'Введите промокод');
});

it('warns when a second code replaces the first (PM-07)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'FIRST10']);
    PromoCode::factory()->percent(20)->create(['code' => 'SECOND20']);
    $cart = promoCart($this->product, 1, 'FIRST10');

    livewireWithCart(CartPage::class, $cart)->set('promoInput', 'SECOND20')->call('applyPromo')
        ->assertSet('promoNotice', 'Промокод FIRST10 заменён на SECOND20');

    expect($cart->refresh()->promo_code)->toBe('SECOND20');
});

it('locks the input after repeated failures with a retry message (PM-14)', function (): void {
    $cart = promoCart($this->product);
    $page = livewireWithCart(CartPage::class, $cart);

    foreach (range(1, PromoAttemptGuard::FAILURES_BEFORE_PAUSE) as $i) {
        $page->set('promoInput', 'WRONG'.$i)->call('applyPromo')->assertSet('promoError', 'Промокод не найден');
    }

    $page->set('promoInput', 'WRONG9')->call('applyPromo');
    expect($page->get('promoError'))->toStartWith('Слишком много попыток. Повторите через');

    // Even a valid code is refused during the pause.
    PromoCode::factory()->create(['code' => 'GOODCODE']);
    $page->set('promoInput', 'GOODCODE')->call('applyPromo');
    expect($page->get('promoError'))->toStartWith('Слишком много попыток')->and($cart->refresh()->promo_code)->toBeNull();
});

it('auto-applies the cookie code of a promo link (PM-23)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'LINK10']);
    $cart = promoCart($this->product, 1);
    Livewire::withCookie('shop_promo', 'link10');

    livewireWithCart(CartPage::class, $cart)->assertSee('Промокод LINK10 применён');

    expect($cart->refresh()->promo_code)->toBe('LINK10')->and($cart->promo_applied_manually)->toBeFalse();
});

it('does not re-apply an auto-applied code after the customer removed it (PM-23)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'LINK10']);
    $cart = promoCart($this->product, 1);
    Livewire::withCookie('shop_promo', 'LINK10');

    livewireWithCart(CartPage::class, $cart)->assertSee('LINK10')->call('removePromo')->assertDontSee('Промокод LINK10 применён');

    expect($cart->refresh()->promo_code)->toBeNull()->and($cart->promo_applied_manually)->toBeTrue();
});

it('does not replace a code the cart already has with the cookie code (PM-23)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'LINK10']);
    PromoCode::factory()->percent(20)->create(['code' => 'OWN20']);
    $cart = promoCart($this->product, 1, 'OWN20');
    Livewire::withCookie('shop_promo', 'LINK10');

    livewireWithCart(CartPage::class, $cart);

    expect($cart->refresh()->promo_code)->toBe('OWN20');
});

it('shows a hint instead of an error when the cookie code does not apply yet (PM-23)', function (): void {
    PromoCode::factory()->create(['code' => 'BIG5000', 'min_order_amount' => '5000.00']);
    $cart = promoCart($this->product, 1);
    Livewire::withCookie('shop_promo', 'BIG5000');

    livewireWithCart(CartPage::class, $cart)
        ->assertSeeHtml('data-promo-hint')->assertSee('Промокод BIG5000 сохранён: Минимальная сумма заказа для кода')
        ->assertDontSeeHtml('data-promo-error');

    expect($cart->refresh()->promo_code)->toBeNull();
});

it('ignores a forged or unknown cookie code silently', function (): void {
    $cart = promoCart($this->product, 1);
    Livewire::withCookie('shop_promo', 'FORGED-CODE');

    livewireWithCart(CartPage::class, $cart)->assertDontSeeHtml('data-promo-hint')->assertDontSeeHtml('data-promo-error');

    expect($cart->refresh()->promo_code)->toBeNull();
});

it('shows free delivery in the totals (PM-02, PM-13)', function (): void {
    PromoCode::factory()->freeDelivery()->create(['code' => 'FREESHIP']);
    $cart = promoCart($this->product, 1, 'FREESHIP');

    livewireWithCart(CartPage::class, $cart)->assertSeeHtml('data-promo-free-delivery')->assertSee('Бесплатная доставка');
});

it('keeps a code that stopped applying visible with its reason and a remove button', function (): void {
    PromoCode::factory()->create(['code' => 'BIG5000', 'min_order_amount' => '5000.00']);
    $cart = promoCart($this->product, 1, 'BIG5000');

    livewireWithCart(CartPage::class, $cart)->assertSee('Промокод BIG5000 сейчас не действует')->assertSeeHtml('data-promo-remove');
});

it('shows the discount in the checkout summary and creates the order with it (PM-13, PM-18)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'SPRING10']);
    $cart = promoCart($this->product, 3, 'SPRING10');

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    $component->assertSee('Скидка по промокоду SPRING10')->assertSeeHtml('data-promo-discount');
    $component->set('delivery', 'kurerskaia-dostavka');
    fillCheckout($component, 'kurerskaia-dostavka')->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();
    expect($order->promo_code)->toBe('SPRING10')->and($order->promo_discount)->toBe('300.00')
        ->and($order->total)->toBe('3000.00')->and($order->delivery_price)->toBe('300.00');
});

it('zeroes the delivery in the checkout summary with a free delivery code (PM-02)', function (): void {
    PromoCode::factory()->freeDelivery()->create(['code' => 'FREESHIP']);
    $cart = promoCart($this->product, 1, 'FREESHIP');

    $component = livewireWithCart(CheckoutForm::class, $cart)->set('delivery', 'kurerskaia-dostavka');
    $component->assertSeeHtml('data-delivery-price');
    expect($component->html())->toContain('Бесплатно');
    $this->travel(5)->seconds();
    fillCheckout($component, 'kurerskaia-dostavka')->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->total)->toBe('1000.00');
});

it('sends the customer back to the cart with the reason and without the code when it is exhausted at checkout (PM-11)', function (): void {
    $code = PromoCode::factory()->percent(10)->create(['code' => 'ONCE10', 'usage_limit' => 1]);
    $cart = promoCart($this->product, 1, 'ONCE10');

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    // Somebody else uses the last application after the form was opened.
    $code->forceFill(['usage_count' => 1])->save();

    fillCheckout($component)->call('submit')->assertRedirect(route('cart'));

    expect(Order::query()->count())->toBe(0)->and($cart->refresh()->promo_code)->toBeNull()
        ->and(session('cart_changed'))->toContain('Промокод исчерпан');
});

it('does not re-apply the cookie code after the checkout refused it (PM-10, PM-23)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'ONCE10', 'per_customer_limit' => 1]);
    promoCheckout(promoCart($this->product, 1, 'ONCE10'), email: 'ivan@example.com', phone: '8 (900) 123-45-67');
    expect(Order::query()->count())->toBe(1);

    $cart = promoCart($this->product, 1);
    Livewire::withCookie('shop_promo', 'ONCE10');
    // The guest got the code from the cookie, the same contact data is over the per-customer limit at checkout.
    livewireWithCart(CartPage::class, $cart)->assertSee('Промокод ONCE10 применён');

    $form = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($form)->call('submit')->assertRedirect(route('cart'));

    expect(Order::query()->count())->toBe(1)->and($cart->refresh()->promo_code)->toBeNull()->and($cart->promo_applied_manually)->toBeTrue();

    livewireWithCart(CartPage::class, $cart)->assertDontSee('Промокод ONCE10 применён');

    expect($cart->refresh()->promo_code)->toBeNull();
});

it('does not show the promo box on the reservation and inquiry forms (PM-07)', function (): void {
    $product = sellable(['stock_qty' => 5]);

    $this->get(route('product.show', $product->slug))->assertOk()->assertDontSeeHtml('data-promo-box');
});

it('renders the promo box on the cart page for a plain request', function (): void {
    $cart = promoCart($this->product);

    $this->withCookie('cart', $cart->token)->get(route('cart'))->assertOk()->assertSee('Есть промокод?');
});
