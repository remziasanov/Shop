<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\OrderItem;
use App\Services\Promo\PromoResult;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockEffect;

/**
 * What saving an editor form would do (OE-10): the positions after the edit, the removed ones, the field values, the
 * totals before and after (kopecks), the stock effect of every position, the log rows and the warnings. Built by
 * {@see OrderEditor}; the preview shows it, the save writes it.
 */
final class EditPlan
{
    /** @var list<PlannedLine> */
    public array $lines = [];

    /** @var list<OrderItem> */
    public array $removed = [];

    /** @var list<StockChange> */
    public array $stockChanges = [];

    /** @var list<StockEffect> */
    public array $effects = [];

    /** @var array<string, string|null> order column => new value (only columns that differ from the stored ones) */
    public array $orderFields = [];

    /** @var list<PlannedChange> */
    public array $changes = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $oldItemsTotal = 0;

    public int $newItemsTotal = 0;

    public int $oldTotal = 0;

    public int $newTotal = 0;

    public int $oldPromoDiscount = 0;

    public int $promoDiscount = 0;

    public int $deliveryPrice = 0;

    public int $manualDiscount = 0;

    /** The order has a promo code whose row is gone: its stored discount is kept as it is (capped by the items total). */
    public bool $promoKept = false;

    public ?PromoResult $promo = null;

    /**
     * Whether saving would change anything. Stock rows only accompany item rows and the derived amounts (items total,
     * total, promo discount) only follow other changes, so neither counts on its own.
     */
    public function hasChanges(): bool
    {
        foreach ($this->changes as $change) {
            if (! in_array($change->field, ['items_total', 'total', 'promo_discount'], true)) {
                return true;
            }
        }

        return false;
    }

    public function totalDelta(): int
    {
        return $this->newTotal - $this->oldTotal;
    }

    public function effectFor(int|string $key): ?StockEffect
    {
        foreach ($this->effects as $effect) {
            if ($effect->key === $key) {
                return $effect;
            }
        }

        return null;
    }
}
