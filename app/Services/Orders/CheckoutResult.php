<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;

/**
 * What one checkout of the cart created (MR-05, MR-11): an order, a reservation or both. When both exist they are linked
 * through `related_order_id`.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public ?Order $order,
        public ?Order $reservation,
    ) {}

    /**
     * The request the customer is sent to first: the order when there is one, otherwise the reservation.
     */
    public function primary(): Order
    {
        return $this->order ?? $this->reservation ?? throw new \LogicException('A checkout creates at least one request.');
    }

    /**
     * @return list<Order> every created request, the order first
     */
    public function all(): array
    {
        return array_values(array_filter([$this->order, $this->reservation]));
    }
}
