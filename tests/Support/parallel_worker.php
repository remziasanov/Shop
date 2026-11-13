<?php

declare(strict_types=1);

/**
 * Child process of ParallelStockMysqlTest: one stock or variant operation in its own PHP process and its own database
 * connection. Usage: php parallel_worker.php deduct <orderId> | hide <variantId>. Prints OK or INSUFFICIENT.
 */

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Stock\StockService;
use App\Services\Variants\VariantService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $id] = $argv;

try {
    match ($action) {
        'deduct' => app(StockService::class)->deductForOrder(Order::query()->findOrFail((int) $id)),
        'hide' => app(VariantService::class)->update(ProductVariant::query()->findOrFail((int) $id), ['is_active' => false]),
    };

    echo 'OK';
} catch (InsufficientStockException) {
    echo 'INSUFFICIENT';
}
