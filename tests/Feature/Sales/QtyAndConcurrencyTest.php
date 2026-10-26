<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\AddToCart;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Services\Cart\CartService;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderNumberGenerator;
use App\Services\Orders\OrderService;
use App\Support\QtyLimit;

beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => false, 'allow_backorder' => true, 'min_order_amount' => 0]);
});

it('seeds one sequence row per request type (D-053)', function (): void {
    expect(OrderSequence::query()->pluck('last_number', 'type')->all())->toBe(['order' => 0, 'reservation' => 0, 'inquiry' => 0]);
});

it('fails clearly instead of creating a missing sequence row inside a transaction', function (): void {
    OrderSequence::query()->where('type', 'order')->delete();

    expect(fn () => app(OrderNumberGenerator::class)->next(OrderType::Order))->toThrow(RuntimeException::class, 'missing');
});

it('checks out a cart only once: the second attempt finds it empty (no second order)', function (): void {
    $product = sellable();
    $cart = cartWith($product);

    app(OrderService::class)->createFromCart($cart, checkoutData());

    expect(fn () => app(OrderService::class)->createFromCart($cart, checkoutData()))
        ->toThrow(SalesException::class, 'Корзина пуста');
    expect(Order::query()->count())->toBe(1);
});

it('clamps a huge quantity in the cart service', function (): void {
    $product = sellable(['stock_qty' => null]);
    $cart = cartWith($product);
    CartItem::query()->delete();

    $item = app(CartService::class)->add($cart, $product, 1_000_000);
    expect($item->qty)->toBe(QtyLimit::MAX);

    $item = app(CartService::class)->add($cart, $product, 5);
    expect($item->qty)->toBe(QtyLimit::MAX);

    $item = app(CartService::class)->update($cart, $product->id, 99_999_999_999);
    expect($item->qty)->toBe(QtyLimit::MAX);
});

it('refuses a huge quantity in the add-to-cart component and the cart page', function (): void {
    $product = sellable(['stock_qty' => null]);

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id, 'showQty' => true])
        ->set('qty', '99999999999')
        ->call('add')
        ->assertHasErrors('cart');

    expect(CartItem::query()->count())->toBe(0);

    $cart = cartWith($product);
    livewireWithCart(CartPage::class, $cart)->set('qtys.'.$product->id, '99999999999');

    expect(CartItem::query()->where('cart_id', $cart->id)->firstOrFail()->qty)->toBe(QtyLimit::MAX);
});

it('refuses a huge quantity for reservations and inquiries', function (): void {
    $product = sellable(['stock_qty' => null]);

    expect(fn () => app(OrderService::class)->createReservation($product, reservationData(1_000_000)))->toThrow(SalesException::class);
    expect(fn () => app(OrderService::class)->createInquiry($product, new InquiryData(1_000_000, 'Иван', '+7 900 123-45-67', null, null, true, '127.0.0.1', 'Pest')))->toThrow(SalesException::class);

    livewireWithCart(ReservationForm::class, null, ['productId' => $product->id])
        ->set('qty', 1_000_000)->call('submit')->assertHasErrors('qty');
    livewireWithCart(InquiryForm::class, null, ['productId' => $product->id])
        ->set('qty', 1_000_000)->call('submit')->assertHasErrors('qty');
});

it('locks the product id of the storefront components', function (string $component): void {
    $product = sellable();

    livewireWithCart($component, null, ['productId' => $product->id])->set('productId', $product->id + 1);
})->with([AddToCart::class, ReservationForm::class, InquiryForm::class])->throws(Exception::class, 'Cannot update locked property');
