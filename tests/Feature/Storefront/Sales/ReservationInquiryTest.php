<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Order;
use App\Models\Product;

beforeEach(function () {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'reservation_hours' => 48]);
});

function reservationFor(Product $product, array $params = [])
{
    $component = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id] + $params);
    test()->travel(5)->seconds();

    return $component;
}

function fillContacts($component, array $overrides = [])
{
    foreach (array_merge(['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'comment' => 'Заберу сам', 'consent' => true], $overrides) as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

it('creates a reservation with a term and shows the term on the thank-you page (SF-50, SF-52)', function () {
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
    $product = sellable(['stock_qty' => 5, 'sales_mode' => SalesMode::Both, 'base_price' => '500.00']);

    $component = fillContacts(reservationFor($product))->set('qty', 2)->call('submit')->assertHasNoErrors();
    $order = Order::query()->firstOrFail();

    $component->assertRedirect(route('order.thanks', $order->uuid));

    expect($order->type)->toBe(OrderType::Reservation)
        ->and($order->reserved_until?->format('Y-m-d H:i'))->toBe('2026-10-28 12:00')
        ->and($order->items->first()->qty)->toBe(2)
        ->and($product->fresh()->reserved_qty)->toBe(2);

    $this->get(route('order.thanks', $order->uuid))->assertOk()
        ->assertSee('Резерв действует до 28.10 12:00')
        ->assertSee($order->number);
});

it('rejects a desired date later than the reservation term (SF-50)', function () {
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
    $product = sellable(['stock_qty' => 5, 'sales_mode' => SalesMode::Reservation]);

    fillContacts(reservationFor($product))->set('desiredDate', '2026-10-30')->call('submit')
        ->assertHasErrors('form')
        ->assertSee('Желаемая дата забора');

    expect(Order::query()->count())->toBe(0);

    fillContacts(reservationFor($product))->set('desiredDate', '2026-10-28')->call('submit')->assertHasNoErrors();
    expect(Order::query()->firstOrFail()->desired_date?->format('Y-m-d'))->toBe('2026-10-28');
});

it('rejects a quantity above the available stock', function () {
    $product = sellable(['stock_qty' => 3, 'sales_mode' => SalesMode::Reservation]);

    fillContacts(reservationFor($product))->set('qty', 4)->call('submit')->assertHasErrors(['qty']);

    expect(Order::query()->count())->toBe(0)->and($product->fresh()->reserved_qty)->toBe(0);
});

it('requires consent and a valid phone for a reservation', function () {
    $product = sellable(['stock_qty' => 3, 'sales_mode' => SalesMode::Reservation]);

    fillContacts(reservationFor($product), ['consent' => false])->call('submit')->assertHasErrors('consent');
    fillContacts(reservationFor($product), ['phone' => '123'])->call('submit')->assertHasErrors('phone');
});

it('does not offer a reservation for a cart-only product, even if the form is called directly', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart]);

    fillContacts(reservationFor($product))->call('submit')->assertHasErrors('form');

    expect(Order::query()->count())->toBe(0);
});

it('creates an inquiry without touching stock; price is empty for «Цена по запросу» (SF-60)', function () {
    $product = sellable(['sales_mode' => SalesMode::Inquiry, 'price_on_request' => true, 'stock_qty' => 4]);

    $component = livewireWithCart(InquiryForm::class, null, ['productId' => $product->id]);
    $this->travel(5)->seconds();
    $component = fillContacts($component)->set('qty', 2)->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();
    $component->assertRedirect(route('order.thanks', $order->uuid));

    expect($order->type)->toBe(OrderType::Inquiry)
        ->and($order->items->first()->price)->toBeNull()
        ->and($order->total)->toBeNull()
        ->and($product->fresh()->stock_qty)->toBe(4)
        ->and($product->fresh()->reserved_qty)->toBe(0);

    $this->get(route('order.thanks', $order->uuid))->assertOk()->assertSee('Цена по запросу')->assertDontSee('Резерв действует');
});

it('is protected by the same guard as the checkout (SF-48)', function () {
    $product = sellable(['sales_mode' => SalesMode::Inquiry]);

    $component = livewireWithCart(InquiryForm::class, null, ['productId' => $product->id]);
    fillContacts($component)->call('submit')->assertHasErrors('form')->assertSee('Слишком быстро');

    $this->travel(5)->seconds();
    fillContacts($component)->set('website', 'x')->call('submit')->assertHasErrors('form');

    expect(Order::query()->count())->toBe(0);
});

it('opens the modal on load from ?inquiry=1 and ?reserve=1 and links cards to it', function () {
    $product = sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 0]);
    sales(['allow_backorder' => false]);

    $this->get(route('product.show', $product->slug).'?inquiry=1')->assertOk()
        ->assertSee('open: true', false)
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false);

    $this->get(route('product.show', $product->slug))->assertOk()->assertDontSee('open: true', false);

    $this->get(route('catalog.index'))->assertSee(route('product.show', $product->slug).'?inquiry=1', false);
});
