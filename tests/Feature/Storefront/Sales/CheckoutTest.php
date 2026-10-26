<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Support\Facades\Crypt;

beforeEach(function () {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

/** Opens the checkout, waits out the anti-bot delay and returns the component. */
function checkoutFor($cart, bool $wait = true)
{
    $component = livewireWithCart(CheckoutForm::class, $cart);

    if ($wait) {
        test()->travel(5)->seconds();
    }

    return $component;
}

it('redirects an empty cart to the cart page', function () {
    $this->get(route('checkout'))->assertRedirect(route('cart'));
});

it('renders the form with a consent checkbox that is not checked and the methods (SF-44, SF-71)', function () {
    $cart = cartWith(sellable());

    $this->withCookie('cart', $cart->token)->get(route('checkout'))->assertOk()
        ->assertSee('Самовывоз')->assertSee('Курьерская доставка')->assertSee('Наличными при получении')
        ->assertSee('Я согласен(на) на обработку персональных данных')
        ->assertDontSee('type="checkbox" wire:model="consent" aria-required="true" class="mt-1 h-4 w-4 flex-none rounded border-slate-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary" checked', false)
        ->assertSee('noindex', false);
});

it('creates the order, decreases stock and redirects to the thank-you page (SF-44, SF-47)', function () {
    $a = sellable(['name' => 'Кресло А', 'base_price' => '1000.00', 'stock_qty' => 10, 'sales_mode' => SalesMode::Cart]);
    $cart = cartWith($a);
    $cart->items()->update(['qty' => 2]);

    $component = fillCheckout(checkoutFor($cart))->call('submit')->assertHasNoErrors();
    $order = Order::query()->firstOrFail();

    $component->assertRedirect(route('order.thanks', $order->uuid));

    expect($order->type)->toBe(OrderType::Order)
        ->and($order->number)->toStartWith('Z-')
        ->and($order->customer_phone)->toBe('+79001234567')
        ->and($order->delivery_price)->toBe('0.00')
        ->and($order->items_total)->toBe('2000.00')
        ->and($order->consent_ip)->toBe('127.0.0.1')
        ->and($order->items)->toHaveCount(1)
        ->and($a->fresh()->stock_qty)->toBe(8)
        ->and(CartItem::query()->count())->toBe(0);

    $this->get(route('order.thanks', $order->uuid))->assertOk()->assertSee($order->number)->assertSee('Кресло А');
});

it('applies the delivery price and the free-from threshold in the summary and the order (SF-45)', function () {
    $product = sellable(['base_price' => '1000.00', 'stock_qty' => 10]);
    $cart = cartWith($product);

    $component = checkoutFor($cart)->set('delivery', 'kurerskaia-dostavka');
    $component->assertSeeHtml('data-delivery-price')->assertSeeText("300\u{00A0}₽")->assertSeeText("1\u{202F}300\u{00A0}₽");

    $component->set('delivery', 'samovyvoz')->assertSeeText('Бесплатно');

    fillCheckout($component, 'kurerskaia-dostavka')->call('submit');
    expect(Order::query()->firstOrFail()->delivery_price)->toBe('300.00');

    $big = sellable(['base_price' => '5000.00', 'stock_qty' => 10]);
    $cart2 = cartWith($big);
    fillCheckout(checkoutFor($cart2), 'kurerskaia-dostavka')->call('submit');

    expect(Order::query()->latest('id')->first()->delivery_price)->toBe('0.00');
});

it('requires an address only for a method that needs one', function () {
    $cart = cartWith(sellable());

    fillCheckout(checkoutFor($cart), 'kurerskaia-dostavka', ['address' => ''])
        ->call('submit')
        ->assertHasErrors(['address' => 'required']);

    fillCheckout(checkoutFor($cart), 'samovyvoz', ['address' => ''])
        ->call('submit')
        ->assertHasNoErrors();

    expect(Order::query()->count())->toBe(1);
});

it('requires the consent (SF-71)', function () {
    $cart = cartWith(sellable());

    fillCheckout(checkoutFor($cart), 'samovyvoz', ['consent' => false])
        ->call('submit')
        ->assertHasErrors(['consent'])
        ->assertSee('Необходимо согласие на обработку персональных данных.');

    expect(Order::query()->count())->toBe(0);
});

it('normalizes the phone and rejects an invalid one (SF-44)', function (string $input, ?string $stored) {
    $cart = cartWith(sellable());

    $component = fillCheckout(checkoutFor($cart), 'samovyvoz', ['phone' => $input])->call('submit');

    if ($stored === null) {
        $component->assertHasErrors(['phone']);
        expect(Order::query()->count())->toBe(0);
    } else {
        $component->assertHasNoErrors();
        expect(Order::query()->firstOrFail()->customer_phone)->toBe($stored);
    }
})->with([
    'with eight' => ['8 (900) 123-45-67', '+79001234567'],
    'masked' => ['+7 (900) 123-45-67', '+79001234567'],
    'too short' => ['+7 (900) 123', null],
    'letters' => ['abc', null],
]);

it('rejects a filled honeypot and a too fast submit (SF-48)', function () {
    $cart = cartWith(sellable());

    fillCheckout(checkoutFor($cart), 'samovyvoz', ['website' => 'http://spam.example'])->call('submit')->assertHasErrors('form');
    fillCheckout(checkoutFor($cart, wait: false))->call('submit')->assertHasErrors('form')->assertSee('Слишком быстро');

    expect(Order::query()->count())->toBe(0);
});

it('rejects a forged form token', function () {
    $cart = cartWith(sellable());
    $component = checkoutFor($cart);

    expect(fn () => $component->set('formToken', Crypt::encryptString('1')))->toThrow(Exception::class);
});

it('limits submits to 5 per minute (SF-48)', function () {
    $cart = cartWith(sellable());
    $component = fillCheckout(checkoutFor($cart), 'samovyvoz', ['consent' => true]);

    // An emptied cart passes validation and the guard but fails in the service: five such submits use up the limit.
    CartItem::query()->delete();

    foreach (range(1, 5) as $i) {
        $component->call('submit')->assertSee('Корзина пуста.');
    }

    $component->call('submit')->assertSee('Слишком много отправок');
});

it('sends the customer back to the cart with the reasons when a price changed (SF-46)', function () {
    $product = sellable(['name' => 'Лампа Сфера', 'base_price' => '700.00']);
    $cart = cartWith($product);
    $component = fillCheckout(checkoutFor($cart));
    $product->update(['base_price' => '900.00']);

    $component->call('submit')->assertRedirect(route('cart'));

    expect(Order::query()->count())->toBe(0)
        ->and(session('cart_changed'))->toBeArray()->not->toBeEmpty();

    $this->withCookie('cart', $cart->token)->withSession(['cart_changed' => session('cart_changed')])->get(route('cart'))
        ->assertOk()->assertSee('Корзина изменилась')->assertSee('Лампа Сфера');
});
