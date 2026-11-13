<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * 22.8: the parallel test on the last available code (two PHP processes, two MySQL connections; SQLite ignores FOR
 * UPDATE). This process is the first buyer and keeps its checkout transaction open, holding the product lock and the
 * promo code row; the child checks out the same code and has to wait, then finds it used up.
 */
beforeEach(function (): void {
    $this->committedFixtures = false;
});

afterEach(function (): void {
    if (DB::getDriverName() === 'mysql' && ($this->committedFixtures ?? false)) {
        Artisan::call('migrate:fresh');
    }
});

it('lets only one of two parallel checkouts take the last use of a code (PM-11)', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $product = sellable(['base_price' => '1000.00', 'stock_qty' => 10]);
    $code = PromoCode::factory()->create(['code' => 'LASTONE', 'usage_limit' => 1]);
    $first = promoCart($product, 1, 'LASTONE');
    $second = promoCart($product, 1, 'LASTONE');

    DB::commit();
    $this->committedFixtures = true;

    DB::beginTransaction();
    $order = promoCheckout($first);

    $child = new Process([PHP_BINARY, base_path('tests/Support/parallel_worker.php'), 'checkout', (string) $second->id], base_path());
    $child->setTimeout(60);
    $child->start();
    usleep(1_500_000);

    // The child waits for the product row locked by this transaction.
    expect($child->isRunning())->toBeTrue();

    DB::commit();
    $child->wait();

    expect($child->getOutput())->toBe('EXHAUSTED')
        ->and(Order::query()->count())->toBe(1)
        ->and(PromoRedemption::query()->count())->toBe(1)
        ->and($order->refresh()->promo_code)->toBe('LASTONE')
        ->and(PromoCode::query()->find($code->id)->usage_count)->toBe(1)
        ->and(Product::query()->find($product->id)->stock_qty)->toBe(9);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');

it('lets the second checkout through when the first one cancels meanwhile (PM-12, lock order)', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $product = sellable(['base_price' => '1000.00', 'stock_qty' => 10]);
    $code = PromoCode::factory()->create(['code' => 'LASTONE', 'usage_limit' => 1]);
    $order = promoCheckout(promoCart($product, 1, 'LASTONE'));
    $second = promoCart($product, 1, 'LASTONE');

    DB::commit();
    $this->committedFixtures = true;

    // The cancellation (order, product, promo code) is in flight while the child starts its checkout.
    DB::beginTransaction();
    app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled);

    $child = new Process([PHP_BINARY, base_path('tests/Support/parallel_worker.php'), 'checkout', (string) $second->id], base_path());
    $child->setTimeout(60);
    $child->start();
    usleep(1_500_000);
    expect($child->isRunning())->toBeTrue();

    DB::commit();
    $child->wait();

    expect($child->getOutput())->toBe('OK')
        ->and(PromoCode::query()->find($code->id)->usage_count)->toBe(1)
        ->and(PromoRedemption::query()->whereNull('released_at')->count())->toBe(1)
        ->and(PromoRedemption::query()->whereNotNull('released_at')->count())->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');
