<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * MR-07, 23.8: two customers reserve the last unit through the cart at the same moment (two PHP processes, two MySQL
 * connections). SQLite ignores FOR UPDATE, so the lock pass of the checkout can only be proven on MySQL. The fixtures
 * are committed and the database is rebuilt at the end (same pattern as the variant stock test).
 */
beforeEach(function (): void {
    $this->committedFixtures = false;
});

afterEach(function (): void {
    if (DB::getDriverName() === 'mysql' && ($this->committedFixtures ?? false)) {
        Artisan::call('migrate:fresh');
    }
});

it('lets only one of two customers reserve the last unit (MR-07)', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'reservation_via_cart' => true]);
    $last = sellable(['sales_mode' => SalesMode::Reservation, 'stock_qty' => 1]);
    $other = sellable(['sales_mode' => SalesMode::Reservation, 'stock_qty' => 5]);
    $first = Cart::factory()->create();
    $second = Cart::factory()->create();

    foreach ([$first, $second] as $cart) {
        CartItem::factory()->forProduct($other, 1)->create(['cart_id' => $cart->id]);
        CartItem::factory()->forProduct($last, 1)->create(['cart_id' => $cart->id]);
    }

    DB::commit();
    $this->committedFixtures = true;

    // The first buyer's checkout is in flight (uncommitted, holding the product locks) when the second one starts.
    DB::beginTransaction();
    app(OrderService::class)->createFromCart($first, mrReserveData());

    $process = new Process([PHP_BINARY, base_path('tests/Support/parallel_worker.php'), 'checkout', (string) $second->id], base_path());
    $process->setTimeout(60);
    $process->start();
    usleep(1_500_000);

    // The second checkout waits for the product lock.
    expect($process->isRunning())->toBeTrue();

    DB::commit();
    $process->wait();

    // The loser is sent back to the cart (the last unit is gone), nothing of its cart is held, one reservation exists.
    expect($process->getOutput())->toBe('CHANGED')
        ->and(Order::query()->where('type', OrderType::Reservation->value)->count())->toBe(1)
        ->and(Product::query()->find($last->id)->reserved_qty)->toBe(1)
        ->and(Product::query()->find($other->id)->reserved_qty)->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');
