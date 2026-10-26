<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\PriceHistory;
use App\Models\Product;
use App\Support\Money;

/**
 * Writes price history rows for changes of the price inputs of a product (PR-06, D-028).
 * Changes of final_price/display_old_price made by rules are never recorded. Creation records nothing:
 * history starts with the first change.
 */
final class PriceHistoryRecorder
{
    /** @var list<string> */
    public const TRACKED_FIELDS = ['base_price', 'cost_price', 'fixed_price', 'compare_at_price'];

    public function __construct(private readonly PriceChangeContext $context) {}

    /**
     * Call after an update was saved. Returns the number of rows written.
     */
    public function recordUpdate(Product $product): int
    {
        $rows = [];
        $now = now();

        foreach (self::TRACKED_FIELDS as $field) {
            if (! $product->wasChanged($field)) {
                continue;
            }

            $old = $this->kopecks($product->getOriginal($field));
            $new = $this->kopecks($product->getAttribute($field));

            if ($old === $new) {
                continue;
            }

            $rows[] = [
                'product_id' => $product->id,
                'field' => $field,
                'old_value' => $old !== null ? Money::toRubles($old) : null,
                'new_value' => $new !== null ? Money::toRubles($new) : null,
                'source' => $this->context->source()->value,
                'user_id' => $this->context->userId(),
                'import_id' => $this->context->importId(),
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            PriceHistory::query()->insert($rows);
        }

        return count($rows);
    }

    private function kopecks(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : Money::fromRubles((string) $value);
    }
}
