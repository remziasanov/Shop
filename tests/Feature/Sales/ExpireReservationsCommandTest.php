<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'reservation_hours' => 48]);
});

it('expires overdue reservations, returns reserved stock and is idempotent (SF-53)', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $overdue = app(OrderService::class)->createReservation($product, reservationData(3));
    $active = app(OrderService::class)->createReservation($product, reservationData(1));
    expect($product->fresh()->reserved_qty)->toBe(4);

    $overdue->forceFill(['reserved_until' => now()->subHour()])->save();

    $this->artisan('shop:expire-reservations')->expectsOutput('Истекло резервов: 1')->assertSuccessful();

    expect($overdue->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($active->fresh()->status)->toBe(OrderStatus::New)
        ->and($product->fresh()->reserved_qty)->toBe(1)
        ->and($product->fresh()->stock_qty)->toBe(10)
        ->and($overdue->fresh()->statusHistory->last()->user_id)->toBeNull();

    $this->artisan('shop:expire-reservations')->expectsOutput('Истекло резервов: 0')->assertSuccessful();

    expect($product->fresh()->reserved_qty)->toBe(1);
});

it('does not touch finished reservations', function (): void {
    $done = Order::factory()->reservation()->status(OrderStatus::Completed)->reservedUntil(now()->subDay())->create();

    $this->artisan('shop:expire-reservations')->assertSuccessful();

    expect($done->fresh()->status)->toBe(OrderStatus::Completed);
});

it('stops at the time budget and continues on the next run', function (): void {
    Order::factory()->reservation()->count(3)->reservedUntil(now()->subHour())->create();
    $service = app(OrderService::class);

    expect($service->expireReservations(microtime(true) - 1))->toBe(0)
        ->and(Order::query()->overdueReservations()->count())->toBe(3)
        ->and($service->expireReservations(microtime(true) + 30))->toBe(3)
        ->and(Order::query()->overdueReservations()->count())->toBe(0);
});

it('processes more than one batch', function (): void {
    Order::factory()->reservation()->count(105)->reservedUntil(now()->subHour())->create();

    expect(app(OrderService::class)->expireReservations())->toBe(105);
});

it('is scheduled every five minutes without overlapping (section 8)', function (): void {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('shop:expire-reservations');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'shop:expire-reservations'));

    expect($event->expression)->toBe('*/5 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});
