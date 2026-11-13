<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Stock\StockService;
use App\Services\Variants\VariantAggregator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real parallelism (two PHP processes, two MySQL connections): SQLite ignores FOR UPDATE, so the lock order and the
 * "second order for the last unit is refused" rule can only be proven on MySQL. The fixtures are committed (a second
 * connection cannot see the test transaction) and the database is rebuilt at the end.
 *
 * The test process plays the first buyer and keeps its transaction open (holding the card and variant locks); the child
 * process then has to wait for the card lock and works on the committed result.
 */
function startWorker(string $action, int $id): Process
{
    $process = new Process([PHP_BINARY, base_path('tests/Support/parallel_worker.php'), $action, (string) $id], base_path());
    $process->setTimeout(60);
    $process->start();

    return $process;
}

afterEach(function (): void {
    if (DB::getDriverName() === 'mysql' && $this->committedFixtures ?? false) {
        Artisan::call('migrate:fresh');
    }
});

it('refuses the second order for the last unit of a variant when two processes race for it', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $size = optionWith('Размер', ['S']);
    $card = variantCard([], $size);
    $variant = variantOf($card, [$size->values[0]], ['stock_qty' => 1]);
    $first = variantOrder(OrderType::Order, $card, $variant, 1);
    $second = variantOrder(OrderType::Order, $card, $variant, 1);

    DB::commit();
    $this->committedFixtures = true;

    DB::beginTransaction();
    app(StockService::class)->deductForOrder($first);

    $child = startWorker('deduct', $second->id);
    usleep(1_500_000);

    // The child waits for the card lock held by this transaction.
    expect($child->isRunning())->toBeTrue();

    DB::commit();
    $child->wait();

    expect($child->getOutput())->toBe('INSUFFICIENT')
        ->and(ProductVariant::query()->find($variant->id)->stock_qty)->toBe(0)
        ->and(Product::query()->find($card->id)->stock_qty)->toBe(0)
        ->and(Order::query()->find($second->id)->stock_applied)->toBeFalse()
        ->and(Order::query()->find($first->id)->stock_applied)->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');

it('does not overwrite a concurrent checkout with a stale aggregate (VR-06, 19.5)', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    $a = variantOf($card, [$size->values[0]], ['stock_qty' => 5]);
    $b = variantOf($card, [$size->values[1]], ['stock_qty' => 4]);
    $order = variantOrder(OrderType::Order, $card, $a, 2);

    DB::commit();
    $this->committedFixtures = true;

    // The checkout is in flight (uncommitted); the manager hides variant B in another process meanwhile.
    DB::beginTransaction();
    app(StockService::class)->deductForOrder($order);

    $child = startWorker('hide', $b->id);
    usleep(1_500_000);
    expect($child->isRunning())->toBeTrue();

    DB::commit();
    $child->wait();

    expect($child->getOutput())->toBe('OK')
        ->and(ProductVariant::query()->find($a->id)->stock_qty)->toBe(3)
        // Only A is active now: the aggregate has to be A's committed stock, not the 5 or 9 seen before the checkout.
        ->and(Product::query()->find($card->id)->stock_qty)->toBe(3)
        ->and(app(VariantAggregator::class)->discrepancies())->toBe([]);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');
