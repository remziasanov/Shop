<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;

/**
 * Allowed status transitions per request type (Appendix B, OR-04).
 * `expired` is reached only by the system (SF-53), so it is not in the manual lists.
 */
final class StatusTransitions
{
    /**
     * Transitions a manager can make.
     *
     * @return list<OrderStatus>
     */
    public function allowed(OrderType $type, OrderStatus $from): array
    {
        return match ($type) {
            OrderType::Order => match ($from) {
                OrderStatus::New => [OrderStatus::Confirmed, OrderStatus::Cancelled],
                OrderStatus::Confirmed => [OrderStatus::Ready, OrderStatus::Shipped, OrderStatus::Cancelled],
                OrderStatus::Ready, OrderStatus::Shipped => [OrderStatus::Completed, OrderStatus::Cancelled],
                default => [],
            },
            OrderType::Reservation => match ($from) {
                OrderStatus::New, OrderStatus::Reserved => array_values(array_filter(
                    [OrderStatus::Reserved, OrderStatus::Completed, OrderStatus::Cancelled],
                    fn (OrderStatus $to): bool => $to !== $from,
                )),
                default => [],
            },
            OrderType::Inquiry => match ($from) {
                OrderStatus::New => [OrderStatus::InProgress, OrderStatus::Closed],
                OrderStatus::InProgress => [OrderStatus::Closed],
                default => [],
            },
        };
    }

    /**
     * @param  bool  $bySystem  allows the automatic reservation expiry
     */
    public function canTransition(OrderType $type, OrderStatus $from, OrderStatus $to, bool $bySystem = false): bool
    {
        if ($bySystem && $to === OrderStatus::Expired) {
            return $type === OrderType::Reservation && in_array($from, [OrderStatus::New, OrderStatus::Reserved], true);
        }

        return in_array($to, $this->allowed($type, $from), true);
    }
}
