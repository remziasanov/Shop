<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/order_edit.php';

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * OE-08: the optimistic lock under real concurrency (two PHP processes, two MySQL connections; SQLite ignores FOR
 * UPDATE). This process saves an edit and keeps its transaction open, holding the order lock; the child opened its form
 * with the same version, has to wait for the lock and is then rejected instead of overwriting the first save.
 */
beforeEach(function (): void {
    $this->committedFixtures = false;
});

afterEach(function (): void {
    if (DB::getDriverName() === 'mysql' && ($this->committedFixtures ?? false)) {
        Artisan::call('migrate:fresh');
    }
});

function startEditChild(int $orderId, int $version, int $qty): Process
{
    $child = new Process([PHP_BINARY, base_path('tests/Support/parallel_worker.php'), 'edit', (string) $orderId, (string) $version, (string) $qty], base_path());
    $child->setTimeout(60);
    $child->start();
    usleep(1_500_000);

    return $child;
}

it('lets only one of two parallel saves of the same version through (OE-08)', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $editor = User::factory()->admin()->create(['name' => 'Редактор']);
    $version = (int) $order->version;

    DB::commit();
    $this->committedFixtures = true;

    DB::beginTransaction();
    app(OrderEditor::class)->apply($order->id, $version, withQty($order, $product, 3), $editor);

    $child = startEditChild($order->id, $version, 7);
    // The child waits for the order row locked by this transaction.
    expect($child->isRunning())->toBeTrue();

    DB::commit();
    $child->wait();

    expect($child->getOutput())->toBe('CONFLICT')
        ->and($order->refresh()->items->first()->qty)->toBe(3)
        ->and((int) $order->version)->toBe($version + 1)
        ->and(Product::query()->find($product->id)->stock_qty)->toBe(7);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');

it('rejects a save made from a form opened before a status change that is still in flight (OE-08)', function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    User::factory()->admin()->create(['name' => 'Редактор']);
    $version = (int) $order->version;

    DB::commit();
    $this->committedFixtures = true;

    DB::beginTransaction();
    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Confirmed);

    $child = startEditChild($order->id, $version, 5);
    expect($child->isRunning())->toBeTrue();

    DB::commit();
    $child->wait();

    expect($child->getOutput())->toBe('CONFLICT')
        ->and($order->refresh()->items->first()->qty)->toBe(2)
        ->and($order->status)->toBe(OrderStatus::Confirmed)
        ->and(Product::query()->find($product->id)->stock_qty)->toBe(8);
})->skip(fn () => DB::getDriverName() !== 'mysql', 'FOR UPDATE needs MySQL');
