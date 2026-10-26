<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Product;
use App\Support\Money;

/**
 * Computes the denormalized price columns of a product (D-015, simplified until stage 2):
 *
 *   final_price       = fixed_price ?? base_price
 *   display_old_price = compare_at_price, only if it is greater than final_price
 *
 * All arithmetic is done in integer kopecks ({@see Money}); no floats. Stage 2 replaces the internals
 * with PriceCalculator but keeps {@see apply()} as the single entry point.
 */
final class ProductPriceUpdater
{
    /**
     * Fill final_price, display_old_price and price_calculated_at on the (unsaved) model.
     */
    public function apply(Product $product): void
    {
        $result = $this->calculate(
            $this->nullableString($product->base_price),
            $this->nullableString($product->fixed_price),
            $this->nullableString($product->compare_at_price),
        );

        $product->final_price = $result['final_price'];
        $product->display_old_price = $result['display_old_price'];
        $product->price_calculated_at = now();
    }

    /**
     * Whether a save requires recalculation (new product or any price input changed).
     */
    public function needsUpdate(Product $product): bool
    {
        return ! $product->exists
            || $product->price_calculated_at === null
            || $product->isDirty(['base_price', 'fixed_price', 'compare_at_price', 'price_on_request']);
    }

    /**
     * Pure calculation on rubles strings ("1290.50"), usable for live previews in forms.
     *
     * @return array{final_price: string, display_old_price: string|null}
     */
    public function calculate(?string $basePrice, ?string $fixedPrice, ?string $compareAtPrice): array
    {
        $final = $fixedPrice !== null ? Money::fromRubles($fixedPrice) : Money::fromRubles($basePrice);
        $final = max(0, $final);

        $old = $compareAtPrice !== null ? Money::fromRubles($compareAtPrice) : 0;

        return [
            'final_price' => Money::toRubles($final),
            'display_old_price' => $old > $final ? Money::toRubles($old) : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
