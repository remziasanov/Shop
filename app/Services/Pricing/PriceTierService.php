<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CustomerGroup;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Rules\RublesAmount;
use App\Services\Catalog\CatalogVersion;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Exact group prices (`min_qty` 1) and quantity tiers (`min_qty` >= 2) of a product or variant for a customer group:
 * validation (GP-14), replacing the set in one transaction and the price history (GP-16). Rule and discount driven price
 * changes are not recorded in the history.
 *
 * Rows are `['min_qty' => 10, 'price' => '900.00']` (rubles as the admin enters them); blank rows are ignored.
 * A card with variants has no tiers of its own: its variants have (VR-20).
 */
final class PriceTierService
{
    public const MAX_TIERS = 10;

    public function __construct(
        private readonly PriceChangeContext $context,
        private readonly GroupPriceRecalculator $groupPrices,
    ) {}

    /**
     * Current rows of the item and the group for a form: `[['min_qty' => 1, 'price' => '1080.00'], ...]` ascending.
     *
     * @return list<array{min_qty: int, price: string}>
     */
    public function rows(Product|ProductVariant $item, CustomerGroup $group): array
    {
        return $this->query($item, $group)->orderBy('min_qty')->get(['min_qty', 'price'])
            ->map(fn (ProductPriceTier $row): array => ['min_qty' => $row->min_qty, 'price' => (string) $row->price])
            ->values()
            ->all();
    }

    /**
     * Validate the rows without saving: errors for the form and warnings that do not block saving.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function validate(Product|ProductVariant $item, CustomerGroup $group, array $rows): TierValidation
    {
        $errors = [];
        $parsed = [];
        $seen = [];

        if ($item instanceof Product && $item->has_variants) {
            return new TierValidation([], [new TierIssue('card_has_variants', __('pricing.tiers.errors.card_has_variants'))], []);
        }

        foreach ($rows as $row) {
            $rawQty = $row['min_qty'] ?? null;
            $rawPrice = $row['price'] ?? null;

            if (($rawQty === null || $rawQty === '') && ($rawPrice === null || $rawPrice === '')) {
                continue;
            }

            $qty = $this->parseQty($rawQty);

            if ($qty === null) {
                $errors[] = new TierIssue('invalid_qty', __('pricing.tiers.errors.invalid_qty'));

                continue;
            }

            $price = $this->parsePrice($rawPrice);

            if ($price === null) {
                $errors[] = new TierIssue('invalid_price', __('pricing.tiers.errors.invalid_price', ['qty' => $qty]), $qty);

                continue;
            }

            if (isset($seen[$qty])) {
                $errors[] = new TierIssue('duplicate_qty', __('pricing.tiers.errors.duplicate_qty', ['qty' => $qty]), $qty);

                continue;
            }

            $seen[$qty] = true;
            $parsed[$qty] = $price;
        }

        ksort($parsed);

        if (count($parsed) > self::MAX_TIERS) {
            $errors[] = new TierIssue('too_many', __('pricing.tiers.errors.too_many', ['max' => self::MAX_TIERS]));
        }

        $previous = null;

        foreach ($parsed as $qty => $price) {
            if ($previous !== null && $price > $previous[1]) {
                $errors[] = new TierIssue('price_grows', __('pricing.tiers.errors.price_grows', [
                    'qty' => $qty,
                    'price' => Money::format($price),
                    'previous_qty' => $previous[0],
                    'previous_price' => Money::format($previous[1]),
                ]), $qty);
            }

            $previous = [$qty, $price];
        }

        $normalized = [];

        foreach ($parsed as $qty => $price) {
            $normalized[] = ['min_qty' => $qty, 'price' => $price];
        }

        return new TierValidation($normalized, $errors, $errors === [] ? $this->warnings($item, $group, $parsed) : []);
    }

    /**
     * Replace the whole set of the item and the group. Rows that are not in `$rows` are deleted. Writes the price history
     * rows (GP-16) and recomputes the group price row of the item when the exact price changed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  bool  $recalculate  false for imports that queue one recalculation at the end
     *
     * @throws ValidationException when the rows are invalid (key `tiers`)
     */
    public function replace(Product|ProductVariant $item, CustomerGroup $group, array $rows, bool $recalculate = true): TierSaveResult
    {
        $validation = $this->validate($item, $group, $rows);

        if (! $validation->passes()) {
            throw ValidationException::withMessages(['tiers' => $validation->errorMessages()]);
        }

        $productId = $item instanceof ProductVariant ? $item->product_id : $item->id;
        $variantId = $item instanceof ProductVariant ? $item->id : null;
        $wanted = [];

        foreach ($validation->rows as $row) {
            $wanted[$row['min_qty']] = $row['price'];
        }

        $created = $updated = $deleted = 0;
        $exactChanged = false;

        DB::transaction(function () use ($item, $group, $productId, $variantId, $wanted, &$created, &$updated, &$deleted, &$exactChanged): void {
            $history = [];

            /** @var array<int, ProductPriceTier> $existing */
            $existing = $this->query($item, $group)->lockForUpdate()->get()->keyBy('min_qty')->all();

            foreach ($existing as $minQty => $tier) {
                if (isset($wanted[$minQty])) {
                    continue;
                }

                $history[] = $this->historyRow($productId, $variantId, $group->id, $minQty, Money::fromRubles((string) $tier->price), null);
                $tier->delete();
                $deleted++;
                $exactChanged = $exactChanged || $minQty === 1;
            }

            foreach ($wanted as $minQty => $price) {
                $tier = $existing[$minQty] ?? null;

                if ($tier === null) {
                    ProductPriceTier::query()->create([
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'customer_group_id' => $group->id,
                        'min_qty' => $minQty,
                        'price' => Money::toRubles($price),
                    ]);
                    $history[] = $this->historyRow($productId, $variantId, $group->id, $minQty, null, $price);
                    $created++;
                } else {
                    $old = Money::fromRubles((string) $tier->price);

                    if ($old === $price) {
                        continue;
                    }

                    $tier->update(['price' => Money::toRubles($price)]);
                    $history[] = $this->historyRow($productId, $variantId, $group->id, $minQty, $old, $price);
                    $updated++;
                }

                $exactChanged = $exactChanged || $minQty === 1;
            }

            if ($history !== []) {
                PriceHistory::query()->insert($history);
            }
        });

        if ($created + $updated + $deleted > 0) {
            if ($recalculate && $exactChanged) {
                $this->groupPrices->recalculate([$productId], [$group->id]);
            }

            // Tiers are applied when the price is resolved: cached catalog fragments must not keep the old ones.
            CatalogVersion::bump();
        }

        return new TierSaveResult($created, $updated, $deleted, $validation->warnings);
    }

    /**
     * Remove every exact price and tier of the item for the group.
     */
    public function clear(Product|ProductVariant $item, CustomerGroup $group, bool $recalculate = true): TierSaveResult
    {
        return $this->replace($item, $group, [], $recalculate);
    }

    /**
     * @param  array<int, int>  $rows  min_qty => price in kopecks, ascending
     * @return list<TierIssue>
     */
    private function warnings(Product|ProductVariant $item, CustomerGroup $group, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $retail = $item->retailPriceKopecks();
        // The price of the group without tiers: the exact price of the set, otherwise retail with rules and discount.
        $base = $rows[1] ?? $this->groupPrices->ruleBasedPrice($item, $group)->finalPrice;
        $warnings = [];

        foreach ($rows as $qty => $price) {
            if ($price > $retail) {
                $warnings[] = new TierIssue('above_retail', __('pricing.tiers.warnings.above_retail', [
                    'qty' => $qty, 'price' => Money::format($price), 'retail' => Money::format($retail),
                ]), $qty);
            }

            if ($qty >= 2 && $price > $base) {
                $warnings[] = new TierIssue('above_group_price', __('pricing.tiers.warnings.above_group_price', [
                    'qty' => $qty, 'price' => Money::format($price), 'group_price' => Money::format($base),
                ]), $qty);
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function historyRow(int $productId, ?int $variantId, int $groupId, int $minQty, ?int $old, ?int $new): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'customer_group_id' => $groupId,
            'min_qty' => $minQty,
            'field' => $minQty === 1 ? 'group_price' : 'group_tier',
            'old_value' => $old !== null ? Money::toRubles($old) : null,
            'new_value' => $new !== null ? Money::toRubles($new) : null,
            'source' => $this->context->source()->value,
            'user_id' => $this->context->userId(),
            'import_id' => $this->context->importId(),
            'created_at' => now(),
        ];
    }

    /**
     * @return Builder<ProductPriceTier>
     */
    private function query(Product|ProductVariant $item, CustomerGroup $group): Builder
    {
        return ProductPriceTier::query()
            ->where('customer_group_id', $group->id)
            ->where('product_id', $item instanceof ProductVariant ? $item->product_id : $item->id)
            ->where('variant_key', $item instanceof ProductVariant ? $item->id : 0);
    }

    private function parseQty(mixed $value): ?int
    {
        if (is_int($value)) {
            $qty = $value;
        } elseif (is_string($value) && preg_match('/^\s*\d{1,9}\s*$/', $value) === 1) {
            $qty = (int) trim($value);
        } else {
            return null;
        }

        return $qty >= 1 ? $qty : null;
    }

    private function parsePrice(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        try {
            $kopecks = Money::fromRubles($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $kopecks > 0 && $kopecks <= RublesAmount::DEFAULT_MAX_KOPECKS ? $kopecks : null;
    }
}
