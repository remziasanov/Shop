<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Checkout found that prices, availability or stock changed since the items were added (SF-46, PR-07).
 * No order was created; the cart was already updated to the new state.
 */
final class CartChangedException extends SalesException
{
    /**
     * @param  list<string>  $reasons  Russian, human readable
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(__('sales.checkout.changed_intro'));
    }
}
