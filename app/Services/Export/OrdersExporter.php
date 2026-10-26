<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Request export to .xlsx (EX-02): one row per item, with the order data repeated on every row.
 * The set of orders is described by a serializable parameter array, see {@see OrderQuery}, so the same
 * export can run in the request or in the queue.
 *
 * @phpstan-import-type Params from OrderQuery
 */
final class OrdersExporter
{
    public const CHUNK = 200;

    /**
     * @param  Params  $params
     * @return Builder<Order>
     */
    public function query(array $params): Builder
    {
        return OrderQuery::apply(Order::query(), $params);
    }

    /**
     * Number of rows the file will have: items of the matching orders (an order without items takes one row).
     *
     * @param  Params  $params
     */
    public function count(array $params): int
    {
        $ids = $this->query($params)->select('orders.id');

        return OrderItem::query()->whereIn('order_id', $ids)->count()
            + $this->query($params)->whereDoesntHave('items')->count();
    }

    /**
     * Write the file and return the number of rows.
     *
     * @param  Params  $params
     */
    public function export(array $params, string $path): int
    {
        $book = new XlsxBook($path);
        $rows = 0;

        try {
            $book->sheet(__('admin_orders.export.sheet'), [1 => 12, 2 => 10, 3 => 22, 4 => 17, 5 => 26, 6 => 18, 7 => 26, 8 => 24, 9 => 36, 10 => 22, 11 => 16, 12 => 44, 17 => 44]);
            $book->header(array_map(
                fn (string $key): string => __('admin_orders.export.columns.'.$key),
                ['number', 'type', 'status', 'created_at', 'customer_name', 'phone', 'email', 'delivery', 'address', 'payment', 'sku', 'item_name', 'price', 'qty', 'line_total', 'order_total', 'comment'],
            ));

            $this->query($params)
                ->with('items')
                ->chunkById(self::CHUNK, function ($orders) use ($book, &$rows): void {
                    foreach ($orders as $order) {
                        if ($order->items->isEmpty()) {
                            $book->row($this->row($order, null));
                            $rows++;

                            continue;
                        }

                        foreach ($order->items as $item) {
                            $book->row($this->row($order, $item));
                            $rows++;
                        }
                    }
                }, 'orders.id', 'id');
        } finally {
            $book->close();
        }

        return $rows;
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    private function row(Order $order, ?OrderItem $item): array
    {
        return [
            $order->number,
            $order->type->label(),
            $order->status->label($order->type),
            $order->created_at?->format('d.m.Y H:i'),
            $order->customer_name,
            $order->customer_phone,
            $order->customer_email,
            $order->delivery_method,
            $order->delivery_address,
            $order->payment_method,
            $item?->sku,
            $item?->name,
            self::number($item?->price),
            $item?->qty,
            self::number($item?->total),
            self::number($order->total),
            $order->customer_comment,
        ];
    }

    private static function number(?string $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
