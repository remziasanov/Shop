<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/order_edit.php';

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\EditStockShortageException;
use App\Exceptions\SalesException;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\Product;
use App\Services\Orders\EditItem;
use App\Services\Orders\OrderService;
use App\Services\Stock\StockService;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

// ------------------------------------------------------------------ order: add / remove / change (OE-04)

it('deducts stock for an added position and returns it for a removed one', function (): void {
    $a = sellable(['stock_qty' => 10]);
    $b = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$a, 2]]);
    expect($a->fresh()->stock_qty)->toBe(8);

    saveEdit($order, addItem(editData($order), $b, 3));

    expect($b->fresh()->stock_qty)->toBe(7)
        ->and($order->refresh()->items)->toHaveCount(2)
        ->and($order->items->last()->stock_applied_qty)->toBe(3);

    $keep = editData($order)->items;
    saveEdit($order, editData($order)->with(['items' => [$keep[1]]]));

    expect($a->fresh()->stock_qty)->toBe(10)
        ->and($b->fresh()->stock_qty)->toBe(7)
        ->and($order->refresh()->items)->toHaveCount(1);
});

it('deducts the increase and returns the decrease of a quantity', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);

    saveEdit($order, withQty($order, $product, 5));
    expect($product->fresh()->stock_qty)->toBe(5)
        ->and($order->items->first()->fresh()->stock_applied_qty)->toBe(5);

    saveEdit($order, withQty($order, $product, 1));
    expect($product->fresh()->stock_qty)->toBe(9)
        ->and($order->items->first()->fresh()->stock_applied_qty)->toBe(1);
});

it('does not touch stock when the quantity is the same, whatever else changes', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);

    saveEdit($order, editData($order, ['customerPhone' => '+7 900 555-44-33', 'deliveryPrice' => 30000]));

    expect($product->fresh()->stock_qty)->toBe(8)
        ->and($order->refresh()->customer_phone)->toBe('+79005554433');
});

it('saving the same form again changes nothing: no stock, no version, no log (idempotent)', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $version = $order->version;

    $result = saveEdit($order, editData($order));

    expect($result->changed)->toBeFalse()
        ->and($product->fresh()->stock_qty)->toBe(8)
        ->and($order->refresh()->version)->toBe($version)
        ->and(OrderChange::query()->count())->toBe(0);

    saveEdit($order, withQty($order, $product, 4));
    $again = saveEdit($order, editData($order));

    expect($again->changed)->toBeFalse()
        ->and($product->fresh()->stock_qty)->toBe(6);
});

it('rejects a shortage as a whole with the items and the available quantity (OE-05)', function (): void {
    $a = sellable(['stock_qty' => 10, 'name' => 'Чайник']);
    $b = sellable(['stock_qty' => 2, 'name' => 'Кружка']);
    $order = editableOrder([[$a, 2]]);
    $before = $order->version;

    $data = addItem(withQty($order, $a, 4), $b, 5);

    try {
        saveEdit($order, $data);
        $this->fail('Expected a shortage');
    } catch (EditStockShortageException $e) {
        expect($e->getMessage())->toContain('Кружка')->toContain('доступно 2')
            ->and($e->shortages)->toBe(['Кружка' => 2]);
    }

    // The whole save is rolled back: the first position kept its quantity and its stock.
    expect($a->fresh()->stock_qty)->toBe(8)
        ->and($b->fresh()->stock_qty)->toBe(2)
        ->and($order->refresh()->items)->toHaveCount(1)
        ->and($order->items->first()->qty)->toBe(2)
        ->and($order->version)->toBe($before)
        ->and(OrderChange::query()->count())->toBe(0);
});

it('counts the quantity held by other reservations as unavailable', function (): void {
    $product = sellable(['stock_qty' => 5]);
    $order = editableOrder([[$product, 1]]);
    editableOrder([[$product, 3]], OrderType::Reservation);

    // stock 4, reserved 3: one piece is available, the order wants +2.
    expect(fn () => saveEdit($order, withQty($order, $product, 3)))->toThrow(EditStockShortageException::class);

    saveEdit($order, withQty($order, $product, 2));
    expect($product->fresh()->stock_qty)->toBe(3);
});

it('deducts what is available and back-orders the rest when «под заказ» is on (D-046)', function (): void {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 4]);
    $order = editableOrder([[$product, 1]]);

    saveEdit($order, withQty($order, $product, 6));

    // 3 left: 3 more taken, 2 more are on order.
    $item = $order->refresh()->items->first();
    expect($product->fresh()->stock_qty)->toBe(0)
        ->and($item->qty)->toBe(6)
        ->and($item->stock_applied_qty)->toBe(4);
});

it('does not deduct a back-ordered position when the form is saved again after the stock arrived', function (): void {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 2]);
    $order = editableOrder([[$product, 5]]);
    expect($order->items->first()->stock_applied_qty)->toBe(2);

    app(StockService::class)->setStock($product->fresh(), 50);

    // Same quantity: the stock that arrived is not taken (OE-04).
    saveEdit($order, editData($order, ['deliveryPrice' => 10000]));

    expect($product->fresh()->stock_qty)->toBe(50)
        ->and($order->refresh()->items->first()->stock_applied_qty)->toBe(2);
});

it('lowers the back-ordered part of a position first and returns only the rest', function (): void {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 2]);
    $order = editableOrder([[$product, 5]]);
    // qty 5, applied 2, unapplied 3.
    app(StockService::class)->setStock($product->fresh(), 10);

    saveEdit($order, withQty($order, $product, 3));
    // give 2 <= unapplied 3: nothing goes back to the stock, applied stays 2.
    expect($product->fresh()->stock_qty)->toBe(10)
        ->and($order->refresh()->items->first()->stock_applied_qty)->toBe(2);

    saveEdit($order, withQty($order, $product, 1));
    // give 2 > unapplied 1: 1 from the unapplied part, 1 returned.
    expect($product->fresh()->stock_qty)->toBe(11)
        ->and($order->refresh()->items->first()->stock_applied_qty)->toBe(1);
});

it('does not change stock of an order created while stock tracking was off', function (): void {
    sales(['stock_tracking' => false]);
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    expect($order->stock_applied)->toBeFalse();

    sales(['stock_tracking' => true]);
    saveEdit($order, addItem(withQty($order, $product, 5), sellable(['stock_qty' => 3]), 2));

    expect($product->fresh()->stock_qty)->toBe(10)
        ->and($order->refresh()->items->every(fn ($item): bool => $item->stock_applied_qty === 0))->toBeTrue();
});

it('does not change stock of a product that is not tracked', function (): void {
    $free = sellable(['stock_qty' => null]);
    $order = editableOrder([[$free, 2]]);

    saveEdit($order, withQty($order, $free, 9));

    expect($free->fresh()->stock_qty)->toBeNull()
        ->and($order->refresh()->items->first()->qty)->toBe(9)
        ->and($order->items->first()->stock_applied_qty)->toBe(0);
});

it('returns exactly the applied stock when an edited order is cancelled', function (): void {
    $a = sellable(['stock_qty' => 10]);
    $b = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$a, 2], [$b, 1]]);

    saveEdit($order, addItem(withQty($order, $a, 5), sellable(['stock_qty' => 7]), 2));
    $keep = editData($order)->items;
    saveEdit($order, editData($order)->with(['items' => [$keep[0], $keep[2]]]));

    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Cancelled);

    expect($a->fresh()->stock_qty)->toBe(10)
        ->and($b->fresh()->stock_qty)->toBe(10)
        ->and(Product::query()->pluck('stock_qty')->sort()->values()->all())->toBe([7, 10, 10]);
});

it('returns the applied stock when an edited order is deleted (OE-14, D-051)', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    saveEdit($order, withQty($order, $product, 6));
    expect($product->fresh()->stock_qty)->toBe(4);

    app(OrderService::class)->delete($order->refresh(), editAdmin());

    expect($product->fresh()->stock_qty)->toBe(10)
        ->and(Order::withTrashed()->find($order->id)->trashed())->toBeTrue()
        // The change log survives the soft delete.
        ->and(OrderChange::query()->where('order_id', $order->id)->exists())->toBeTrue();
});

// ------------------------------------------------------------------ reservation (OE-04)

it('holds and releases the reserve of a reservation', function (): void {
    $a = sellable(['stock_qty' => 10]);
    $b = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$a, 2]], OrderType::Reservation);
    expect($a->fresh()->reserved_qty)->toBe(2);

    saveEdit($order, addItem(withQty($order, $a, 5), $b, 1));
    expect($a->fresh()->reserved_qty)->toBe(5)
        ->and($b->fresh()->reserved_qty)->toBe(1)
        ->and($a->fresh()->stock_qty)->toBe(10);

    saveEdit($order, withQty($order, $a, 1));
    expect($a->fresh()->reserved_qty)->toBe(1);

    $keep = editData($order)->items;
    saveEdit($order, editData($order)->with(['items' => [$keep[1]]]));
    expect($a->fresh()->reserved_qty)->toBe(0);
});

it('rejects a reservation increase beyond the available stock even with «под заказ»', function (): void {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 3]);
    $order = editableOrder([[$product, 2]], OrderType::Reservation);

    expect(fn () => saveEdit($order, withQty($order, $product, 4)))->toThrow(EditStockShortageException::class)
        ->and($product->fresh()->reserved_qty)->toBe(2);
});

it('releases the reserve of an edited reservation when it expires or is cancelled', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]], OrderType::Reservation);
    saveEdit($order, withQty($order, $product, 4));

    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Cancelled);

    expect($product->fresh()->reserved_qty)->toBe(0);
});

it('keeps the number, type, creation date, reserved_until and status history of a reservation (OE-15)', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]], OrderType::Reservation);
    $order->forceFill(['created_at' => '2026-01-01 10:00:00'])->saveQuietly();
    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Reserved);
    $order->refresh();
    $snapshot = $order->only(['number', 'type', 'status', 'created_at', 'reserved_until']);
    $historyCount = $order->statusHistory()->count();

    saveEdit($order, withQty($order, $product, 3));

    $order->refresh();
    expect($order->only(['number', 'type', 'status', 'created_at', 'reserved_until']))->toEqual($snapshot)
        ->and($order->statusHistory()->count())->toBe($historyCount);
});

// ------------------------------------------------------------------ variants (VR-06)

it('counts stock per variant and rolls up the card aggregate', function (): void {
    $color = optionWith('Цвет', ['Красный', 'Синий']);
    $card = variantCard([], $color);
    $red = variantOf($card, [$color->values[0]], ['stock_qty' => 5]);
    $blue = variantOf($card, [$color->values[1]], ['stock_qty' => 5]);
    $order = editableOrder([[$red, 2]]);

    saveEdit($order, addItem(withQty($order, $red, 3), $blue, 4));

    expect($red->fresh()->stock_qty)->toBe(2)
        ->and($blue->fresh()->stock_qty)->toBe(1)
        ->and($card->fresh()->stock_qty)->toBe(3);

    expect(fn () => saveEdit($order, withQty($order, $blue, 9)))->toThrow(EditStockShortageException::class);
    expect($blue->fresh()->stock_qty)->toBe(1);
});

it('refuses a card with variants without a variant, and a variant of another card', function (): void {
    $color = optionWith('Цвет', ['Красный']);
    $card = variantCard([], $color);
    variantOf($card, [$color->values[0]], ['stock_qty' => 5]);
    $other = variantCard([], $color);
    $foreign = variantOf($other, [$color->values[0]], ['stock_qty' => 5]);
    $order = editableOrder([[sellable(['stock_qty' => 5]), 1]]);

    expect(fn () => saveEdit($order, editData($order)->with(['items' => [...editData($order)->items, new EditItem(null, $card->id, null, 1)]])))
        ->toThrow(SalesException::class)
        ->and(fn () => saveEdit($order, editData($order)->with(['items' => [...editData($order)->items, new EditItem(null, $card->id, $foreign->id, 1)]])))
        ->toThrow(SalesException::class);
});

it('merges the same product added twice into one position (OE-13)', function (): void {
    $a = sellable(['stock_qty' => 10]);
    $b = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$a, 2]]);

    $data = addItem(addItem(editData($order), $b, 1), $b, 2);
    $data = addItem($data, $a, 1);
    saveEdit($order, $data);

    $items = $order->refresh()->items;
    expect($items)->toHaveCount(2)
        ->and($items->firstWhere('product_id', $a->id)->qty)->toBe(3)
        ->and($items->firstWhere('product_id', $b->id)->qty)->toBe(3)
        ->and($a->fresh()->stock_qty)->toBe(7)
        ->and($b->fresh()->stock_qty)->toBe(7);
});

it('does not let a new inactive product in, but keeps an inactive one that is already in the order (OE-13)', function (): void {
    $in = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$in, 1]]);
    $in->forceFill(['is_active' => false])->save();
    $inactive = sellable(['stock_qty' => 10, 'is_active' => false]);

    saveEdit($order, withQty($order, $in, 3));
    expect($order->refresh()->items->first()->qty)->toBe(3);

    expect(fn () => saveEdit($order, addItem(editData($order), $inactive, 1)))->toThrow(SalesException::class);
});

it('validates the quantity: whole, at least 1, within the limit (OE-13, D-053)', function (int $qty): void {
    $product = sellable(['stock_qty' => null]);
    $order = editableOrder([[$product, 1]]);

    expect(fn () => saveEdit($order, withQty($order, $product, $qty)))->toThrow(SalesException::class);
})->with([0, -2, 1000]);

it('refuses to remove every position', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 5]), 1]]);

    expect(fn () => saveEdit($order, editData($order, ['items' => []])))->toThrow(SalesException::class);
});
