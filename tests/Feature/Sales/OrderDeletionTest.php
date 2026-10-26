<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\SalesMode;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

it('gives the reserved stock back when an active reservation is deleted (D-051)', function (): void {
    $product = sellable(['stock_qty' => 3]);
    $order = app(OrderService::class)->createReservation($product, reservationData(2));
    expect($product->fresh()->reserved_qty)->toBe(2);

    $admin = User::factory()->admin()->create();
    app(OrderService::class)->delete($order, $admin);

    expect($product->fresh()->reserved_qty)->toBe(0)
        ->and($product->fresh()->stock_qty)->toBe(3)
        ->and(Order::query()->count())->toBe(0)
        ->and(Order::withTrashed()->count())->toBe(1);

    $history = OrderStatusHistory::query()->where('order_id', $order->id)->latest('id')->first();
    expect($history->comment)->toBe('Удалено администратором')->and($history->user_id)->toBe($admin->id);
});

it('returns the stock of a new order when it is deleted', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    expect($product->fresh()->stock_qty)->toBe(9);

    app(OrderService::class)->delete($order);

    expect($product->fresh()->stock_qty)->toBe(10);
});

it('returns the stock of orders in every active status', function (OrderStatus $status): void {
    $product = sellable(['stock_qty' => 10]);
    $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    $order->forceFill(['status' => $status])->save();

    app(OrderService::class)->delete($order);

    expect($product->fresh()->stock_qty)->toBe(10);
})->with([OrderStatus::Confirmed, OrderStatus::Ready, OrderStatus::Shipped]);

it('does not touch the stock of a completed order when it is deleted', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed);
    app(OrderService::class)->changeStatus($order, OrderStatus::Ready);
    app(OrderService::class)->changeStatus($order, OrderStatus::Completed);

    app(OrderService::class)->delete($order);

    expect($product->fresh()->stock_qty)->toBe(9);
});

it('does not touch the stock of a cancelled order or an expired reservation twice', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled);
    expect($product->fresh()->stock_qty)->toBe(10);

    app(OrderService::class)->delete($order);

    expect($product->fresh()->stock_qty)->toBe(10);
});

it('is idempotent', function (): void {
    $product = sellable(['stock_qty' => 3]);
    $order = app(OrderService::class)->createReservation($product, reservationData(2));

    app(OrderService::class)->delete($order);
    app(OrderService::class)->delete($order);
    $order->delete();

    expect($product->fresh()->reserved_qty)->toBe(0)
        ->and(OrderStatusHistory::query()->where('order_id', $order->id)->where('comment', 'Удалено администратором')->count())->toBe(1);
});

it('releases the stock even when the model is deleted directly', function (): void {
    $product = sellable(['stock_qty' => 3]);
    $order = app(OrderService::class)->createReservation($product, reservationData(2));

    Order::query()->findOrFail($order->id)->delete();

    expect($product->fresh()->reserved_qty)->toBe(0);
});

it('leaves an inquiry alone', function (): void {
    $product = sellable(['stock_qty' => 3, 'sales_mode' => SalesMode::Inquiry]);
    $order = app(OrderService::class)->createInquiry($product, new InquiryData(1, 'Иван', '+7 900 123-45-67', null, null, true, '127.0.0.1', 'Pest'));

    app(OrderService::class)->delete($order);

    expect($product->fresh()->stock_qty)->toBe(3)->and($product->fresh()->reserved_qty)->toBe(0);
});

it('gives the stock back from the delete action and from bulk delete in the admin', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = sellable(['stock_qty' => 5]);
    $one = app(OrderService::class)->createReservation($product, reservationData(2));
    $two = app(OrderService::class)->createReservation($product, reservationData(1));
    $three = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    expect($product->fresh()->reserved_qty)->toBe(3)->and($product->fresh()->stock_qty)->toBe(4);

    Livewire::test(ViewOrder::class, ['record' => $one->getRouteKey()])->callAction('delete');
    expect($product->fresh()->reserved_qty)->toBe(1);

    Livewire::test(ListOrders::class)->callTableBulkAction('delete', [$two, $three]);

    $fresh = $product->fresh();
    expect($fresh->reserved_qty)->toBe(0)->and($fresh->stock_qty)->toBe(5)->and(Order::query()->count())->toBe(0);

    // The nightly expiry has nothing left to release.
    Carbon::setTestNow(now()->addHours(200));
    Artisan::call('shop:expire-reservations');
    expect($product->fresh()->reserved_qty)->toBe(0);
});
