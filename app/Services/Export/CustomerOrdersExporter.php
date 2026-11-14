<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Throwable;

/**
 * Data of the order history export of a customer (CX-01, CX-02, CX-03, TZ 18.6): the query of the customer's own
 * requests (always `customer_id = :customer`, the `(customer_id, created_at)` index), the Excel file and the chunked
 * order stream for the PDF. The set is described by a serializable parameter array, so the same export can run in the
 * web request or in the queue.
 *
 * Excel: sheet «Заказы» (one row per order) and, with items, sheet «Состав» (one row per item, chunks of 500).
 * Text cells that start with `=`, `+`, `-`, `@` get an apostrophe prefix against formula injection (TZ 18.8).
 *
 * @phpstan-type Params array{from: string, until: string, types: list<string>, statuses: list<string>, with_items: bool}
 */
final class CustomerOrdersExporter
{
    /** Orders per chunk of the PDF data (memory). */
    public const PDF_CHUNK = 100;

    /** Item rows per chunk of the «Состав» sheet. */
    public const ITEM_CHUNK = 500;

    /** Order rows per chunk of the «Заказы» sheet. */
    public const ORDER_CHUNK = 500;

    /**
     * @param  Params  $params
     * @return Builder<Order>
     */
    public function query(Customer $customer, array $params): Builder
    {
        $query = Order::query();
        $this->constrain($query, $customer, $params);

        return $query;
    }

    /**
     * Requests the export would contain.
     *
     * @param  Params  $params
     */
    public function countOrders(Customer $customer, array $params): int
    {
        return $this->query($customer, $params)->count();
    }

    /**
     * Items of those requests.
     *
     * @param  Params  $params
     */
    public function countItems(Customer $customer, array $params): int
    {
        return $this->itemsQuery($customer, $params)->count();
    }

    /**
     * Rows of the Excel file: requests plus, with items, their positions (CX-05).
     *
     * @param  Params  $params
     */
    public function excelRows(Customer $customer, array $params): int
    {
        return $this->countOrders($customer, $params) + ($params['with_items'] ? $this->countItems($customer, $params) : 0);
    }

    /**
     * Orders in chunks of {@see PDF_CHUNK}, oldest first, with their items when asked; no more than one chunk is held.
     *
     * @param  Params  $params
     * @return Generator<int, list<Order>>
     */
    public function chunks(Customer $customer, array $params, bool $withItems): Generator
    {
        $query = $this->query($customer, $params)->orderBy('orders.created_at')->orderBy('orders.id');

        if ($withItems) {
            $query->with('items');
        }

        for ($page = 1; ; $page++) {
            $orders = (clone $query)->forPage($page, self::PDF_CHUNK)->get();

            if ($orders->isEmpty()) {
                return;
            }

            yield array_values($orders->all());

            if ($orders->count() < self::PDF_CHUNK) {
                return;
            }
        }
    }

    /**
     * Writes the .xlsx file and returns the number of rows (orders plus items).
     *
     * @param  Params  $params
     */
    public function writeXlsx(Customer $customer, array $params, string $path): int
    {
        $book = new XlsxBook($path);
        $rows = 0;

        try {
            $book->sheet(__('customer_export.xlsx.orders_sheet'), [1 => 14, 2 => 14, 3 => 18, 4 => 20, 5 => 28, 6 => 14, 7 => 14, 8 => 16]);
            $book->header(array_map(
                fn (string $key): string => __('customer_export.xlsx.orders.'.$key),
                ['number', 'type', 'date', 'status', 'delivery_method', 'delivery_price', 'discount', 'total'],
            ));

            $this->query($customer, $params)
                ->chunkById(self::ORDER_CHUNK, function ($orders) use ($book, &$rows): void {
                    foreach ($orders as $order) {
                        $book->row($this->orderRow($order));
                        $rows++;
                    }
                }, 'orders.id', 'id');

            if ($params['with_items']) {
                $book->sheet(__('customer_export.xlsx.items_sheet'), [1 => 14, 2 => 16, 3 => 44, 4 => 26, 5 => 10, 6 => 14, 7 => 14]);
                $book->header(array_map(
                    fn (string $key): string => __('customer_export.xlsx.items.'.$key),
                    ['number', 'sku', 'name', 'variant', 'qty', 'price', 'sum'],
                ));

                $this->itemsQuery($customer, $params)
                    ->select('order_items.*', 'orders.number as order_number')
                    ->chunkById(self::ITEM_CHUNK, function ($items) use ($book, &$rows): void {
                        foreach ($items as $item) {
                            $book->row($this->itemRow($item));
                            $rows++;
                        }
                    }, 'order_items.id', 'id');
            }
        } finally {
            $book->close();
        }

        return $rows;
    }

    /**
     * A text for a spreadsheet cell: the apostrophe prefix stops a spreadsheet program from treating it as a formula.
     */
    public static function safeText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true) ? "'".$value : $value;
    }

    /**
     * @param  Params  $params
     * @return Builder<OrderItem>
     */
    private function itemsQuery(Customer $customer, array $params): Builder
    {
        $query = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')->whereNull('orders.deleted_at');
        $this->constrain($query, $customer, $params);

        return $query;
    }

    /**
     * @param  Builder<*>|QueryBuilder  $query  a query on `orders` or joined with it
     * @param  Params  $params
     */
    private function constrain(Builder|QueryBuilder $query, Customer $customer, array $params): void
    {
        $query->where('orders.customer_id', $customer->id);

        if (($from = self::date($params['from'])) !== null) {
            $query->where('orders.created_at', '>=', $from->startOfDay());
        }

        if (($until = self::date($params['until'])) !== null) {
            $query->where('orders.created_at', '<=', $until->endOfDay());
        }

        if ($params['types'] !== []) {
            $query->whereIn('orders.type', $params['types']);
        }

        if ($params['statuses'] !== []) {
            $query->whereIn('orders.status', $params['statuses']);
        }
    }

    /**
     * @return list<string|int|float|null>
     */
    private function orderRow(Order $order): array
    {
        $discount = Money::fromRubles((string) $order->promo_discount) + Money::fromRubles((string) $order->manual_discount);

        return [
            self::safeText($order->number),
            $order->type->label(),
            $order->created_at?->format('d.m.Y H:i'),
            $order->status->label($order->type),
            self::safeText($order->delivery_method),
            self::number($order->delivery_price),
            self::number(Money::toRubles($discount)),
            self::number($order->total),
        ];
    }

    /**
     * @return list<string|int|float|null>
     */
    private function itemRow(OrderItem $item): array
    {
        return [
            self::safeText((string) $item->getAttribute('order_number')),
            self::safeText($item->sku),
            self::safeText($item->name),
            self::safeText($item->variant_label),
            $item->qty,
            self::number($item->price),
            self::number($item->total),
        ];
    }

    /**
     * An exact decimal string as the number of an Excel cell (the file format has no decimal type).
     */
    private static function number(?string $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function date(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
