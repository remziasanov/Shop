<?php

declare(strict_types=1);

namespace App\Services\Cart;

/**
 * Result of «Повторить заказ» (CA-13): how many positions of the order reached the cart and which did not.
 */
final class ReorderReport
{
    /**
     * @param  list<string>  $skipped  names of the positions that could not be added
     */
    public function __construct(
        public readonly int $added,
        public readonly int $total,
        public readonly array $skipped,
    ) {}

    public function summary(): string
    {
        $text = __('account.orders.reorder_report', ['added' => $this->added, 'total' => $this->total]);

        if ($this->skipped !== []) {
            $text .= ' '.__('account.orders.reorder_skipped', ['items' => implode(', ', $this->skipped)]);
        }

        return $text;
    }
}
