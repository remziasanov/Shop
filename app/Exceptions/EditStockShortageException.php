<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An order edit asks for more than is available and back-ordering is not possible (OE-05). The whole edit is rolled
 * back; the message lists every short item with the available quantity.
 */
final class EditStockShortageException extends SalesException
{
    /**
     * @param  array<string, int>  $shortages  item name => available quantity
     */
    public function __construct(public readonly array $shortages)
    {
        $parts = [];

        foreach ($shortages as $name => $available) {
            $parts[] = __('sales.errors.insufficient_stock', ['name' => $name, 'available' => $available]);
        }

        parent::__construct(implode(' ', $parts));
    }
}
