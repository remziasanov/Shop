<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\EditData;
use App\Services\Orders\EditItem;
use App\Services\Orders\EditResult;
use App\Services\Orders\OrderEditor;
use App\Services\Stock\StockService;
use App\Support\Money;

/**
 * An order / reservation with the given lines as it is after checkout: items snapshotted, totals set, stock applied
 * through StockService (as in production). Lines are [Product|ProductVariant, qty] pairs.
 *
 * @param  list<array{0: Product|ProductVariant, 1: int}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function editableOrder(array $lines, OrderType $type = OrderType::Order, array $attributes = []): Order
{
    $order = Order::factory()->{$type->value}()->create($attributes);
    $sum = 0;

    foreach ($lines as [$unit, $qty]) {
        $item = $unit instanceof ProductVariant ? OrderItem::factory()->forVariant($unit, $qty) : OrderItem::factory()->forProduct($unit, $qty);
        $created = $item->create(['order_id' => $order->id]);
        $sum += Money::fromRubles($created->total);
    }

    $order->forceFill(['items_total' => Money::toRubles($sum), 'total' => Money::toRubles($sum)])->save();

    $type === OrderType::Reservation
        ? app(StockService::class)->holdForReservation($order)
        : app(StockService::class)->deductForOrder($order);

    return $order->refresh()->load('items');
}

/**
 * The editor form data of the order as it stands, with some fields replaced (see EditData::with()).
 *
 * @param  array<string, mixed>  $changes
 */
function editData(Order $order, array $changes = []): EditData
{
    return EditData::fromOrder($order->refresh()->load('items'))->with($changes);
}

/**
 * The form data with the quantity of the item of the unit replaced.
 */
function withQty(Order $order, Product|ProductVariant $unit, int $qty): EditData
{
    $order->refresh()->load('items');
    $items = $order->items->map(function (OrderItem $item) use ($unit, $qty): EditItem {
        $match = $unit instanceof ProductVariant ? $item->variant_id === $unit->id : ($item->variant_id === null && $item->product_id === $unit->id);

        return EditItem::fromItem($item, $match ? $qty : null);
    })->all();

    return EditData::fromOrder($order)->with(['items' => array_values($items)]);
}

function addItem(EditData $data, Product|ProductVariant $unit, int $qty, ?int $price = null): EditData
{
    $item = $unit instanceof ProductVariant
        ? new EditItem(null, $unit->product_id, $unit->id, $qty, $price)
        : new EditItem(null, $unit->id, null, $qty, $price);

    return $data->with(['items' => [...$data->items, $item]]);
}

function saveEdit(Order $order, EditData $data, ?User $actor = null): EditResult
{
    $order->refresh();

    return app(OrderEditor::class)->apply($order->id, (int) $order->version, $data, $actor ?? User::factory()->admin()->create());
}

function editManager(): User
{
    return User::factory()->manager()->create();
}

function editAdmin(): User
{
    return User::factory()->admin()->create();
}
