<?php

declare(strict_types=1);

/**
 * Child process of ParallelStockMysqlTest: one stock or variant operation in its own PHP process and its own database
 * connection. Usage: php parallel_worker.php deduct <orderId> | hide <variantId> | checkout <cartId> | edit <orderId> <version> <qty>. Prints OK, INSUFFICIENT, CONFLICT (an order edit with a stale version), or EXHAUSTED (a promo code ran out, 22.8).
 */

use App\Exceptions\CartChangedException;
use App\Exceptions\ConcurrentEditException;
use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\EditData;
use App\Services\Orders\EditItem;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use App\Services\Stock\StockService;
use App\Services\Variants\VariantService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/sales.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $id] = $argv;
$extra = array_slice($argv, 3);

try {
    match ($action) {
        'deduct' => app(StockService::class)->deductForOrder(Order::query()->findOrFail((int) $id)),
        'hide' => app(VariantService::class)->update(ProductVariant::query()->findOrFail((int) $id), ['is_active' => false]),
        'checkout' => app(OrderService::class)->createFromCart(Cart::query()->findOrFail((int) $id), checkoutData()),
        // edit <orderId> <expectedVersion> <qty of the first item>: one order editor save (24.5, OE-08).
        'edit' => (function () use ($id, $extra): void {
            $order = Order::query()->with('items')->findOrFail((int) $id);
            $data = EditData::fromOrder($order)->with(['items' => [EditItem::fromItem($order->items->first(), (int) $extra[1])]]);
            app(OrderEditor::class)->apply($order->id, (int) $extra[0], $data, User::query()->where('name', 'Редактор')->firstOrFail());
        })(),
    };

    echo 'OK';
} catch (ConcurrentEditException) {
    echo 'CONFLICT';
} catch (InsufficientStockException) {
    echo 'INSUFFICIENT';
} catch (CartChangedException $e) {
    echo in_array(__('promo.messages.exhausted'), $e->reasons, true) ? 'EXHAUSTED' : 'CHANGED';
}
