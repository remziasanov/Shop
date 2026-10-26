<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Settings\PricingSettings;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Glue between products and {@see PriceCalculator} (D-015): feeds it the cached rules, the pricing
 * settings and the current time, and writes the denormalized price columns (PR-02).
 *
 * Entry points: {@see apply()} (unsaved model, used by the observer), {@see calculate()} / {@see explain()}
 * (previews, no writes) and {@see recalculate()} (bulk, no observers, no price history).
 */
final class ProductPriceUpdater
{
    public const CHUNK_SIZE = 500;

    /** Columns needed to compute and compare prices. */
    private const PRICE_COLUMNS = [
        'id', 'category_id', 'brand', 'is_featured', 'is_new',
        'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price', 'display_old_price',
    ];

    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly PriceRuleRepository $rules,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * Fill final_price, display_old_price and price_calculated_at on the (unsaved) model.
     */
    public function apply(Product $product): void
    {
        $result = $this->explain($product);

        $product->final_price = $result->finalRubles();
        $product->display_old_price = $result->oldRubles();
        $product->price_calculated_at = now();
    }

    /**
     * Whether a save requires recalculation: a new product, or any input of the price or of rule matching changed.
     */
    public function needsUpdate(Product $product): bool
    {
        return ! $product->exists
            || $product->price_calculated_at === null
            || $product->isDirty([
                'base_price', 'cost_price', 'fixed_price', 'compare_at_price', 'price_on_request',
                'category_id', 'brand', 'is_featured', 'is_new',
            ]);
    }

    /**
     * Full result with the explanation for a product (or a prepared input) using the current rules,
     * settings and time.
     */
    public function explain(Product|PriceInput $subject, ?CarbonInterface $now = null): PriceResult
    {
        $input = $subject instanceof Product ? $this->inputFor($subject) : $subject;

        return $this->calculator->calculate(
            $input,
            $this->rules->activeRules(),
            $now ?? now(),
            PricingConfig::fromSettings(app(PricingSettings::class)),
        );
    }

    /**
     * Preview on rubles strings ("1290.50"), e.g. for live form previews. The optional arguments are the
     * rule-matching inputs; without them only rules for all products can match.
     *
     * @return array{final_price: string, display_old_price: string|null}
     *
     * @throws InvalidArgumentException when an amount is not valid
     */
    public function calculate(
        ?string $basePrice,
        ?string $fixedPrice,
        ?string $compareAtPrice,
        ?int $categoryId = null,
        ?string $brand = null,
        bool $isFeatured = false,
        bool $isNew = false,
        ?string $costPrice = null,
        ?int $productId = null,
    ): array {
        $result = $this->explainValues($basePrice, $fixedPrice, $compareAtPrice, $categoryId, $brand, $isFeatured, $isNew, $costPrice, $productId);

        return ['final_price' => $result->finalRubles(), 'display_old_price' => $result->oldRubles()];
    }

    /**
     * Same inputs as {@see calculate()}, but returns the full result with the explanation (PR-03).
     * Pass `$productId` so that rules limited to specific products match.
     *
     * @throws InvalidArgumentException when an amount is not valid
     */
    public function explainValues(
        ?string $basePrice,
        ?string $fixedPrice,
        ?string $compareAtPrice,
        ?int $categoryId = null,
        ?string $brand = null,
        bool $isFeatured = false,
        bool $isNew = false,
        ?string $costPrice = null,
        ?int $productId = null,
    ): PriceResult {
        return $this->explain(new PriceInput(
            productId: $productId,
            categoryId: $categoryId,
            brand: $brand !== null && trim($brand) !== '' ? $brand : null,
            isFeatured: $isFeatured,
            isNew: $isNew,
            basePrice: Money::fromRubles($basePrice),
            costPrice: $costPrice !== null && $costPrice !== '' ? Money::fromRubles($costPrice) : null,
            compareAtPrice: $compareAtPrice !== null && $compareAtPrice !== '' ? Money::fromRubles($compareAtPrice) : null,
            fixedPrice: $fixedPrice !== null && $fixedPrice !== '' ? Money::fromRubles($fixedPrice) : null,
            categoryPathIds: $categoryId !== null ? $this->categories->selfAndAncestorIds($categoryId) : [],
        ));
    }

    /**
     * Recompute the price columns of the given products in chunks of {@see CHUNK_SIZE}. Rows are written with
     * plain queries and only when final_price or display_old_price changed: no model events (so no price
     * history) and `updated_at` stays untouched. Soft-deleted products are included.
     *
     * @param  iterable<int>  $productIds
     * @return int number of products whose prices changed
     */
    public function recalculate(iterable $productIds): int
    {
        $config = PricingConfig::fromSettings(app(PricingSettings::class));
        $rules = $this->rules->activeRules();
        $now = now();
        $changed = 0;

        foreach (collect($productIds)->unique()->chunk(self::CHUNK_SIZE) as $chunk) {
            $products = Product::withTrashed()->whereIn('id', $chunk->values()->all())->get(self::PRICE_COLUMNS);

            foreach ($products as $product) {
                $result = $this->calculator->calculate($this->inputFor($product), $rules, $now, $config);

                $sameFinal = Money::fromRubles($product->final_price) === $result->finalPrice;
                $oldPrice = $product->display_old_price !== null ? Money::fromRubles($product->display_old_price) : null;

                if ($sameFinal && $oldPrice === $result->displayOldPrice) {
                    continue;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'final_price' => $result->finalRubles(),
                    'display_old_price' => $result->oldRubles(),
                    'price_calculated_at' => $now,
                ]);

                $changed++;
            }
        }

        return $changed;
    }

    private function inputFor(Product $product): PriceInput
    {
        $categoryId = (int) $product->category_id;
        $path = $categoryId > 0 ? $this->categories->selfAndAncestorIds($categoryId) : [];

        return PriceInput::fromProduct($product, $path);
    }
}
