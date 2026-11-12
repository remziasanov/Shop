<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CategoryTree;
use App\Services\Home\HomePageRenderer;
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
        'id', 'category_id', 'brand', 'is_featured', 'is_new', 'has_variants',
        'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price', 'display_old_price',
    ];

    /** Columns of a variant row needed to compute its price. */
    private const VARIANT_COLUMNS = [
        'id', 'product_id', 'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price', 'display_old_price',
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
        // The maximum belongs to cards with variants only; their aggregates are written by aggregatePrices().
        $product->final_price_max = null;
    }

    /**
     * A card with variants takes its prices from them, so saving it must not recompute its own price columns:
     * {@see recalculateVariantsOf()} runs after the save instead (the observer decides).
     */
    public function isVariantCard(Product $product): bool
    {
        return $product->exists && $product->has_variants && ! $product->isDirty('has_variants');
    }

    /**
     * Whether saving a card with variants changed something its variants inherit (their prices must be recalculated).
     */
    public function variantsNeedUpdate(Product $product): bool
    {
        return $product->has_variants && ($product->wasChanged([
            'base_price', 'cost_price', 'fixed_price', 'compare_at_price', 'category_id', 'brand', 'is_featured', 'is_new', 'deleted_at',
        ]));
    }

    /**
     * Whether a save requires recalculation: a new product, a product restored from the trash (batch recalculation
     * skips deleted products, so its price may be stale), or any input of the price or of rule matching changed.
     */
    public function needsUpdate(Product $product): bool
    {
        return ! $product->exists
            || $product->price_calculated_at === null
            || ($product->isDirty('deleted_at') && $product->deleted_at === null)
            || $product->isDirty([
                'base_price', 'cost_price', 'fixed_price', 'compare_at_price', 'price_on_request',
                'category_id', 'brand', 'is_featured', 'is_new', 'has_variants',
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
     * Recompute the price columns of the given products in chunks of {@see CHUNK_SIZE} priced entities (a product
     * without variants or a variant, VR-07). Rows are written with plain queries and only when final_price or
     * display_old_price changed: no model events (so no price history) and `updated_at` stays untouched. Soft-deleted
     * products are included (their variants are not: a restored card is recalculated on restore). After every batch of
     * variants the aggregates of the touched cards are recomputed.
     *
     * @param  iterable<int>  $productIds
     * @return int number of priced entities (products or variants) whose prices changed
     */
    public function recalculate(iterable $productIds): int
    {
        $config = PricingConfig::fromSettings(app(PricingSettings::class));
        $rules = $this->rules->activeRules();
        $now = now();
        $changed = 0;
        /** @var array<int, Product> $cards cards with variants, by id */
        $cards = [];

        foreach (collect($productIds)->unique()->chunk(self::CHUNK_SIZE) as $chunk) {
            $products = Product::withTrashed()->whereIn('id', $chunk->values()->all())->get(self::PRICE_COLUMNS);

            foreach ($products as $product) {
                if ($product->has_variants) {
                    $cards[$product->id] = $product;

                    continue;
                }

                $result = $this->calculator->calculate($this->inputFor($product), $rules, $now, $config);

                $sameFinal = Money::fromRubles($product->final_price) === $result->finalPrice;
                $oldPrice = $product->display_old_price !== null ? Money::fromRubles($product->display_old_price) : null;

                if ($sameFinal && $oldPrice === $result->displayOldPrice) {
                    continue;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'final_price' => $result->finalRubles(),
                    'display_old_price' => $result->oldRubles(),
                    'final_price_max' => null,
                    'price_calculated_at' => $now,
                ]);

                $changed++;
            }
        }

        if ($cards !== []) {
            $changed += $this->recalculateVariantBatches($cards, $rules, $config, $now);
        }

        if ($changed > 0) {
            // Raw UPDATEs above bypass model events: drop the cached home page (cheap Cache::forget).
            HomePageRenderer::forget();
        }

        return $changed;
    }

    /**
     * Variants of the given cards in batches of {@see CHUNK_SIZE}; the aggregates of the cards touched by a batch are
     * recomputed right after it.
     *
     * @param  array<int, Product>  $cards
     * @param  list<PriceRuleData>  $rules
     */
    private function recalculateVariantBatches(array $cards, array $rules, PricingConfig $config, CarbonInterface $now): int
    {
        $changed = 0;
        $paths = [];
        $ids = array_keys($cards);

        $query = DB::table('product_variants')->whereIn('product_id', $ids)->whereNull('deleted_at');
        $touched = [];

        foreach ($query->select(self::VARIANT_COLUMNS)->lazyById(self::CHUNK_SIZE, 'id')->chunk(self::CHUNK_SIZE) as $batch) {
            foreach ($batch as $row) {
                $card = $cards[(int) $row->product_id];
                $touched[$card->id] = $card->id;
                $variant = (new ProductVariant)->forceFill([
                    'base_price' => $row->base_price,
                    'cost_price' => $row->cost_price,
                    'compare_at_price' => $row->compare_at_price,
                    'fixed_price' => $row->fixed_price,
                ]);
                $paths[$card->id] ??= $this->pathFor($card);

                $result = $this->calculator->calculate(PriceInput::fromVariant($variant, $card, $paths[$card->id]), $rules, $now, $config);
                $oldPrice = $row->display_old_price !== null ? Money::fromRubles((string) $row->display_old_price) : null;

                if (Money::fromRubles((string) $row->final_price) === $result->finalPrice && $oldPrice === $result->displayOldPrice) {
                    continue;
                }

                DB::table('product_variants')->where('id', $row->id)->update([
                    'final_price' => $result->finalRubles(),
                    'display_old_price' => $result->oldRubles(),
                    'price_calculated_at' => $now,
                ]);

                $changed++;
            }

            foreach ($touched as $id) {
                $this->aggregatePrices($id);
            }

            $touched = [];
        }

        // Cards without a live variant keep their stored prices.
        return $changed;
    }

    /**
     * Price of a variant with the explanation, using the card's rule scope (VR-07).
     */
    public function explainVariant(ProductVariant $variant, ?Product $card = null, ?CarbonInterface $now = null): PriceResult
    {
        $card ??= $variant->stockProduct();

        return $this->explain(PriceInput::fromVariant($variant, $card, $this->pathFor($card)), $now);
    }

    /**
     * Fill final_price, display_old_price and price_calculated_at on the (unsaved) variant.
     */
    public function applyToVariant(ProductVariant $variant, ?Product $card = null): void
    {
        $result = $this->explainVariant($variant, $card);

        $variant->final_price = $result->finalRubles();
        $variant->display_old_price = $result->oldRubles();
        $variant->price_calculated_at = now();
    }

    /**
     * Whether saving a variant requires recalculation of its price.
     */
    public function variantNeedsUpdate(ProductVariant $variant): bool
    {
        return ! $variant->exists
            || $variant->price_calculated_at === null
            || ($variant->isDirty('deleted_at') && $variant->deleted_at === null)
            || $variant->isDirty(['base_price', 'cost_price', 'fixed_price', 'compare_at_price']);
    }

    /**
     * Recompute the price of every live variant of the card from the card's current data, then the card aggregates
     * (used when the card, its category or a rule scope changed; no price history). The given model is kept in sync.
     */
    public function recalculateVariantsOf(Product $card): void
    {
        $rules = $this->rules->activeRules();
        $config = PricingConfig::fromSettings(app(PricingSettings::class));
        $now = now();
        $path = $this->pathFor($card);

        foreach (ProductVariant::query()->where('product_id', $card->id)->get() as $variant) {
            $result = $this->calculator->calculate(PriceInput::fromVariant($variant, $card, $path), $rules, $now, $config);

            DB::table('product_variants')->where('id', $variant->id)->update([
                'final_price' => $result->finalRubles(),
                'display_old_price' => $result->oldRubles(),
                'price_calculated_at' => $now,
            ]);
        }

        $this->aggregatePrices($card->id);

        $fresh = DB::table('products')->where('id', $card->id)->first(['final_price', 'final_price_max', 'display_old_price', 'price_calculated_at']);

        if ($fresh !== null) {
            $columns = ['final_price', 'final_price_max', 'display_old_price', 'price_calculated_at'];

            foreach ($columns as $column) {
                $card->setAttribute($column, $fresh->{$column});
            }

            $card->syncOriginalAttributes($columns);
        }
    }

    /**
     * Card prices from its variants (19.5): final_price = MIN, final_price_max = MAX over the active variants (all live
     * variants when none is active), display_old_price = the old price of the cheapest one. Null when the card has no
     * live variant (its stored prices stay).
     *
     * @return array{final_price: string, final_price_max: string, display_old_price: string|null}|null
     */
    public function expectedAggregatePrices(int $productId): ?array
    {
        $rows = DB::table('product_variants')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['is_active', 'final_price', 'display_old_price']);

        if ($rows->isEmpty()) {
            return null;
        }

        $active = $rows->filter(fn (object $row): bool => (bool) $row->is_active);
        $pool = $active->isNotEmpty() ? $active : $rows;

        $min = null;
        $minKopecks = 0;
        $maxKopecks = 0;

        foreach ($pool as $row) {
            $kopecks = Money::fromRubles((string) $row->final_price);

            if ($min === null || $kopecks < $minKopecks) {
                $min = $row;
                $minKopecks = $kopecks;
            }

            $maxKopecks = max($maxKopecks, $kopecks);
        }

        return [
            'final_price' => Money::toRubles($minKopecks),
            'final_price_max' => Money::toRubles($maxKopecks),
            'display_old_price' => $min->display_old_price !== null ? Money::toRubles(Money::fromRubles((string) $min->display_old_price)) : null,
        ];
    }

    /**
     * Write {@see expectedAggregatePrices()} to a card with variants when they differ.
     *
     * @return bool whether the card row changed
     */
    public function aggregatePrices(int $productId): bool
    {
        $expected = $this->expectedAggregatePrices($productId);
        $card = DB::table('products')->where('id', $productId)->where('has_variants', true)->first(['final_price', 'final_price_max', 'display_old_price']);

        if ($expected === null || $card === null) {
            return false;
        }

        $same = bccomp((string) $card->final_price, $expected['final_price'], 2) === 0
            && $card->final_price_max !== null && bccomp((string) $card->final_price_max, $expected['final_price_max'], 2) === 0
            && (($card->display_old_price === null) === ($expected['display_old_price'] === null))
            && ($expected['display_old_price'] === null || bccomp((string) $card->display_old_price, $expected['display_old_price'], 2) === 0);

        if ($same) {
            return false;
        }

        DB::table('products')->where('id', $productId)->update($expected + ['price_calculated_at' => now()]);
        HomePageRenderer::forget();

        return true;
    }

    /**
     * @return list<int>
     */
    private function pathFor(Product $product): array
    {
        $categoryId = (int) $product->category_id;

        return $categoryId > 0 ? $this->categories->selfAndAncestorIds($categoryId) : [];
    }

    private function inputFor(Product $product): PriceInput
    {
        return PriceInput::fromProduct($product, $this->pathFor($product));
    }
}
