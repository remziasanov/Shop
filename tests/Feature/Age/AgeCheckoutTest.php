<?php

declare(strict_types=1);

use App\Enums\AgeRestriction;
use App\Enums\SalesMode;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Order;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReservationData;
use App\Settings\AgeGateSettings;

beforeEach(function () {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

function restrictedSellable(AgeRestriction $level = AgeRestriction::Age18, array $attributes = [])
{
    return sellable(array_merge(['age_restriction' => $level, 'sales_mode' => SalesMode::Both], $attributes));
}

function checkoutOf($cart)
{
    $component = livewireWithCart(CheckoutForm::class, $cart);
    test()->travel(5)->seconds();

    return fillCheckout($component);
}

it('shows the age checkbox in the checkout only when the cart has restricted products (AG-11)', function () {
    ageGate();

    $restricted = cartWith(restrictedSellable());
    $this->withCookie('cart', $restricted->token)->get(route('checkout'))->assertOk()->assertSee('Мне исполнилось 18 лет');

    $plain = cartWith(sellable());
    $this->withCookie('cart', $plain->token)->get(route('checkout'))->assertOk()->assertDontSee('Мне исполнилось');
});

it('refuses the order without the age checkbox and creates nothing (AG-11)', function () {
    ageGate();
    $cart = cartWith(restrictedSellable());

    checkoutOf($cart)->call('submit')->assertHasErrors(['ageConfirmed' => 'accepted']);

    expect(Order::query()->count())->toBe(0);
});

it('creates the order with the confirmed level when the box is ticked (AG-11)', function () {
    ageGate();
    $cart = cartWith(restrictedSellable(AgeRestriction::Age16), restrictedSellable(AgeRestriction::Age18));

    checkoutOf($cart)->set('ageConfirmed', true)->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->age_confirmed_level)->toBe(AgeRestriction::Age18);
});

it('asks for the age of an inherited category restriction (AG-11)', function () {
    ageGate();
    $category = ageCategory(AgeRestriction::Age16);
    $product = ageProduct($category, ['base_price' => '100.00', 'stock_qty' => 5, 'sales_mode' => SalesMode::Both]);

    checkoutOf(cartWith($product))->call('submit')->assertHasErrors(['ageConfirmed' => 'accepted']);
    checkoutOf(cartWith($product))->set('ageConfirmed', true)->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->age_confirmed_level)->toBe(AgeRestriction::Age16);
});

it('does not require the box for an unrestricted cart and stores no level (AG-11)', function () {
    ageGate();

    checkoutOf(cartWith(sellable()))->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->age_confirmed_level)->toBeNull();
});

it('does not require the box while the gate is disabled', function () {
    setSettings(AgeGateSettings::class, ['enabled' => false]);

    checkoutOf(cartWith(restrictedSellable()))->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->age_confirmed_level)->toBeNull();
});

it('checks the age on the server whatever the cookie or the form says (AG-11)', function () {
    ageGate();
    $cart = cartWith(restrictedSellable());

    $data = new CheckoutData(
        name: 'Иван Тестов',
        phone: '8 900 123-45-67',
        email: null,
        deliveryKey: 'samovyvoz',
        address: null,
        paymentKey: 'nalicnymi-pri-polucenii',
        comment: null,
        consent: true,
        ip: '127.0.0.1',
    );

    $this->withUnencryptedCookie('shop_age', '18');
    expect(fn () => app(OrderService::class)->createFromCart($cart, $data))->toThrow(SalesException::class);
    expect(Order::query()->count())->toBe(0);
});

it('requires the age box in the reservation form too (AG-11)', function () {
    ageGate();
    $product = restrictedSellable();

    $component = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id]);
    $this->travel(5)->seconds();

    foreach (['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'consent' => true, 'qty' => 1] as $key => $value) {
        $component->set($key, $value);
    }

    $component->call('submit')->assertHasErrors(['ageConfirmed' => 'accepted']);
    expect(Order::query()->count())->toBe(0);

    $component->set('ageConfirmed', true)->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->age_confirmed_level)->toBe(AgeRestriction::Age18);
});

it('refuses a reservation without the age confirmation on the server (AG-11)', function () {
    ageGate();
    $product = restrictedSellable();

    $data = new ReservationData(qty: 1, name: 'Иван', phone: '8 900 123-45-67', email: null, desiredDate: null, comment: null, consent: true, ip: '127.0.0.1');

    expect(fn () => app(OrderService::class)->createReservation($product, $data))->toThrow(SalesException::class);
    expect(Order::query()->count())->toBe(0);
});
