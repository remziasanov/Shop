<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;

final class InvalidOrderTransitionException extends SalesException
{
    public function __construct(OrderType $type, OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(__('sales.errors.transition', ['from' => $from->label($type), 'to' => $to->label($type)]));
    }
}
