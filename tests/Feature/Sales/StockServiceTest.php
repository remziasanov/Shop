<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Stock\StockService;

function stockOrder(OrderType $type, $product, int $qty): Order
{
    $order = Order::factory()->{$type->value}()->create();
    OrderItem::factory()->forProduct($product, $qty)->create(['order_id' => $order->id]);

    return $order->load('items');
}

beforeEach(function () {
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $this->stock = app(StockService::class);
});

it('deducts stock for an order and remembers the applied quantity', function () {
    $product = sellable(['stock_qty' => 10]);
    $order = stockOrder(OrderType::Order, $product, 3);

    $this->stock->deductForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(7)
        ->and($order->fresh()->stock_applied)->toBeTrue()
        ->and($order->items->first()->fresh()->stock_applied_qty)->toBe(3);
});

it('is idempotent: applying twice does not deduct twice', function () {
    $product = sellable(['stock_qty' => 10]);
    $order = stockOrder(OrderType::Order, $product, 3);

    $this->stock->deductForOrder($order);
    $this->stock->deductForOrder($order);
    expect($product->fresh()->stock_qty)->toBe(7);

    $this->stock->returnForOrder($order);
    $this->stock->returnForOrder($order);
    expect($product->fresh()->stock_qty)->toBe(10)
        ->and($order->fresh()->stock_applied)->toBeFalse();
});

it('returns exactly the deducted quantity on cancel', function () {
    $product = sellable(['stock_qty' => 10]);
    $order = stockOrder(OrderType::Order, $product, 4);
    $this->stock->deductForOrder($order);

    $this->stock->returnForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(10);
});

it('rejects the second order for the last unit (check under lock on fresh data)', function () {
    $product = sellable(['stock_qty' => 1]);
    $first = stockOrder(OrderType::Order, $product, 1);
    // The second request works with a stale model that still shows one unit.
    $stale = $product->fresh();
    $second = stockOrder(OrderType::Order, $stale, 1);

    $this->stock->deductForOrder($first);

    expect(fn () => $this->stock->deductForOrder($second))->toThrow(InsufficientStockException::class)
        ->and($product->fresh()->stock_qty)->toBe(0)
        ->and($second->fresh()->stock_applied)->toBeFalse();
});

it('reports the available quantity in the exception', function () {
    $product = sellable(['stock_qty' => 2]);
    $order = stockOrder(OrderType::Order, $product, 5);

    try {
        $this->stock->deductForOrder($order);
        $this->fail('Expected exception');
    } catch (InsufficientStockException $e) {
        expect($e->available)->toBe(2)->and($e->product->is($product))->toBeTrue();
    }
});

it('does not deduct more than is free when part of the stock is reserved', function () {
    $product = sellable(['stock_qty' => 5, 'reserved_qty' => 4]);
    $order = stockOrder(OrderType::Order, $product, 2);

    expect(fn () => $this->stock->deductForOrder($order))->toThrow(InsufficientStockException::class);
});

it('deducts only the available part with backorder and returns exactly that', function () {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 3]);
    $order = stockOrder(OrderType::Order, $product, 5);

    $this->stock->deductForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(0)
        ->and($order->items->first()->fresh()->stock_applied_qty)->toBe(3);

    $this->stock->returnForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(3);
});

it('never lets stock go negative with backorder', function () {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 0]);
    $order = stockOrder(OrderType::Order, $product, 2);

    $this->stock->deductForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(0);
});

it('holds a reservation, then releases it on cancel', function () {
    $product = sellable(['stock_qty' => 5]);
    $order = stockOrder(OrderType::Reservation, $product, 2);

    $this->stock->holdForReservation($order);
    expect($product->fresh()->reserved_qty)->toBe(2)->and($product->fresh()->stock_qty)->toBe(5);

    $this->stock->releaseReservation($order);
    $this->stock->releaseReservation($order);
    expect($product->fresh()->reserved_qty)->toBe(0)->and($product->fresh()->stock_qty)->toBe(5);
});

it('refuses to hold more than is available, even with backorder', function () {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 2, 'reserved_qty' => 1]);
    $order = stockOrder(OrderType::Reservation, $product, 2);

    expect(fn () => $this->stock->holdForReservation($order))->toThrow(InsufficientStockException::class);
    expect($product->fresh()->reserved_qty)->toBe(1);
});

it('completes a reservation: both reserved and stock go down', function () {
    $product = sellable(['stock_qty' => 5]);
    $order = stockOrder(OrderType::Reservation, $product, 2);
    $this->stock->holdForReservation($order);

    $this->stock->completeReservation($order);
    $this->stock->completeReservation($order);

    expect($product->fresh()->reserved_qty)->toBe(0)->and($product->fresh()->stock_qty)->toBe(3);
});

it('does nothing when stock tracking is off', function () {
    sales(['stock_tracking' => false]);
    $product = sellable(['stock_qty' => 5]);
    $order = stockOrder(OrderType::Order, $product, 2);
    $reservation = stockOrder(OrderType::Reservation, $product, 2);

    $this->stock->deductForOrder($order);
    $this->stock->holdForReservation($reservation);

    expect($product->fresh()->stock_qty)->toBe(5)
        ->and($product->fresh()->reserved_qty)->toBe(0)
        ->and($order->fresh()->stock_applied)->toBeFalse()
        ->and($this->stock->available($product))->toBeNull();
});

it('skips products whose stock is not tracked', function () {
    $product = sellable(['stock_qty' => null]);
    $order = stockOrder(OrderType::Order, $product, 100);

    $this->stock->deductForOrder($order);

    expect($product->fresh()->stock_qty)->toBeNull();
});

it('still returns stock of an order created while tracking was on', function () {
    $product = sellable(['stock_qty' => 5]);
    $order = stockOrder(OrderType::Order, $product, 2);
    $this->stock->deductForOrder($order);

    sales(['stock_tracking' => false]);
    $this->stock->returnForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(5);
});

it('sets an absolute stock value and keeps the model in sync', function () {
    $product = sellable(['stock_qty' => 5]);

    $this->stock->setStock($product, 12);
    expect($product->fresh()->stock_qty)->toBe(12)->and($product->stock_qty)->toBe(12)->and($product->isDirty())->toBeFalse();

    $this->stock->setStock($product, -4);
    expect($product->fresh()->stock_qty)->toBe(0);

    $this->stock->setStock($product, null);
    expect($product->fresh()->stock_qty)->toBeNull();
});

it('computes the available quantity', function () {
    $product = sellable(['stock_qty' => 5, 'reserved_qty' => 2]);
    expect($this->stock->available($product))->toBe(3);

    $product->reserved_qty = 9;
    expect($this->stock->available($product))->toBe(0);

    expect($this->stock->available(sellable(['stock_qty' => null])))->toBeNull();
});

it('returns stock for a product that was soft deleted meanwhile', function () {
    $product = sellable(['stock_qty' => 5]);
    $order = stockOrder(OrderType::Order, $product, 2);
    $this->stock->deductForOrder($order);
    $product->delete();

    $this->stock->returnForOrder($order);

    expect($product->fresh()?->stock_qty ?? Product::withTrashed()->find($product->id)->stock_qty)->toBe(5);
});
