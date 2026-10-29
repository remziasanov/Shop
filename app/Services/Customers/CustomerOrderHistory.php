<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Orders\OrderQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * The order history of the cabinet (CA-11, CA-28): requests of all types of one customer. Every query starts from
 * `customer_id = :customer`, so another customer's request can neither be listed nor opened (the caller answers 404).
 */
final class CustomerOrderHistory
{
    public const PER_PAGE = 20;

    /**
     * @param  array{type?: string|null, status?: string|null, created_from?: string|null, created_until?: string|null}  $filters
     * @param  string  $direction  `desc` (newest first, default) or `asc`
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(Customer $customer, array $filters = [], string $direction = 'desc', int $page = 1): LengthAwarePaginator
    {
        $query = Order::query()->where('orders.customer_id', $customer->id)->withCount('items');

        OrderQuery::apply($query, $filters);

        $dir = $direction === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy('orders.created_at', $dir)
            ->orderBy('orders.id', $dir)
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * The request with its items and status history, or null when the customer has no such request.
     */
    public function find(Customer $customer, string $uuid): ?Order
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }

        return Order::query()
            ->where('orders.customer_id', $customer->id)
            ->where('orders.uuid', $uuid)
            ->with(['items', 'statusHistory'])
            ->first();
    }
}
