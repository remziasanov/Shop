<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Settings\PricingSettings;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maintains `product_group_prices_final` (GP-06, GP-07, TZ 21.5): for every product or variant and every customer
 * group the price of steps 2 and 3 of 21.2 (exact price, else rules bound to the group + group discount), stored only
 * when it differs from the retail price. A card with variants gets a row with `variant_key` 0 holding the minimum over
 * its active variants (all live variants when none is active), stored only when it differs from the card's `final_price`.
 *
 * Works in batches of {@see BATCH_SIZE} products and writes with plain queries (no model events). Reads the retail prices
 * from the database, so run it after the retail recalculation. Only groups that can have a different price are
 * considered: with a discount, with an active rule bound to them, with an exact price, or with rows to clean up.
 */
class GroupPriceRecalculator
{
    public const BATCH_SIZE = 500;

    /** Product fields whose change requires the group prices of the product to be recomputed. */
    public const PRODUCT_FIELDS = [
        'base_price', 'cost_price', 'fixed_price', 'compare_at_price', 'price_on_request',
        'category_id', 'brand', 'is_featured', 'is_new', 'has_variants', 'deleted_at',
    ];

    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly PriceRuleRepository $rules,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * Recompute the group prices of the products.
     *
     * @param  iterable<int>  $productIds
     * @param  list<int>|null  $groupIds  null: every group that can have a different price
     * @return int number of rows written or deleted
     */
    public function recalculate(iterable $productIds, ?array $groupIds = null): int
    {
        if (! $this->anyGroupData()) {
            return 0;
        }

        $groups = $this->relevantGroups($groupIds);

        if ($groups === []) {
            return 0;
        }

        $settings = app(PricingSettings::class);
        $config = PricingConfig::fromSettings($settings);
        $rules = $this->rules->activeRules();
        $now = now();
        $contexts = [];

        foreach ($groups as $id => $discount) {
            $contexts[$id] = new GroupPriceContext($id, $discount, $settings->group_prices_rounding);
        }

        $changed = 0;

        foreach (collect($productIds)->unique()->chunk(self::BATCH_SIZE) as $chunk) {
            $changed += DB::transaction(fn (): int => $this->recalculateBatch($chunk->values()->all(), $contexts, $rules, $config, $now));
        }

        if ($changed > 0) {
            CatalogVersion::bump();
        }

        return $changed;
    }

    /**
     * Price of the item for the group by the rules and the group discount (step 3 of 21.2), ignoring exact prices and
     * tiers. For a variant the card supplies the rule scope.
     */
    public function ruleBasedPrice(Product|ProductVariant $item, CustomerGroup $group): PriceResult
    {
        $card = $item instanceof ProductVariant ? $item->stockProduct() : $item;
        $categoryId = (int) $card->category_id;
        $path = $categoryId > 0 ? $this->categories->selfAndAncestorIds($categoryId) : [];
        $input = $item instanceof ProductVariant ? PriceInput::fromVariant($item, $card, $path) : PriceInput::fromProduct($item, $path);
        $settings = app(PricingSettings::class);

        return $this->calculator->calculate(
            $input,
            $this->rules->activeRules(),
            now(),
            PricingConfig::fromSettings($settings),
            GroupPriceContext::for($group, $settings),
        );
    }

    /**
     * One cheap query: whether any group has a discount, a bound rule, an exact price or stored rows at all. Without
     * any, every group price equals the retail price and there is nothing to do (the common case of a retail shop).
     */
    private function anyGroupData(): bool
    {
        return (bool) DB::scalar(
            'select case when'
            .' exists (select 1 from customer_groups where default_discount_percent > 0)'
            .' or exists (select 1 from price_rule_groups)'
            .' or exists (select 1 from product_price_tiers where min_qty = 1)'
            .' or exists (select 1 from product_group_prices_final)'
            .' then 1 else 0 end'
        );
    }

    /**
     * @param  list<int>|null  $only
     * @return array<int, string> group id => discount percent
     */
    private function relevantGroups(?array $only): array
    {
        $query = DB::table('customer_groups');

        if ($only !== null) {
            $query->whereIn('id', $only);
        }

        /** @var array<int, string> $discounts */
        $discounts = $query->pluck('default_discount_percent', 'id')->map(fn (mixed $value): string => (string) $value)->all();

        if ($discounts === []) {
            return [];
        }

        $ids = array_keys($discounts);
        $relevant = [];

        foreach ($discounts as $id => $discount) {
            if (bccomp($discount, '0', 2) > 0) {
                $relevant[$id] = true;
            }
        }

        $bound = DB::table('price_rule_groups')
            ->join('price_rules', 'price_rules.id', '=', 'price_rule_groups.price_rule_id')
            ->where('price_rules.is_active', true)
            ->whereIn('price_rule_groups.customer_group_id', $ids)
            ->distinct()
            ->pluck('price_rule_groups.customer_group_id');

        $exact = DB::table('product_price_tiers')->where('min_qty', 1)->whereIn('customer_group_id', $ids)->distinct()->pluck('customer_group_id');
        $stored = DB::table('product_group_prices_final')->whereIn('customer_group_id', $ids)->distinct()->pluck('customer_group_id');

        foreach ([$bound, $exact, $stored] as $list) {
            foreach ($list as $id) {
                $relevant[(int) $id] = true;
            }
        }

        return array_intersect_key($discounts, $relevant);
    }

    /**
     * @param  list<int>  $productIds
     * @param  array<int, GroupPriceContext>  $contexts
     * @param  list<PriceRuleData>  $rules
     */
    private function recalculateBatch(array $productIds, array $contexts, array $rules, PricingConfig $config, CarbonInterface $now): int
    {
        $groupIds = array_keys($contexts);

        $products = Product::withTrashed()->whereIn('id', $productIds)->get([
            'id', 'category_id', 'brand', 'is_featured', 'is_new', 'has_variants',
            'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price', 'display_old_price', 'deleted_at',
        ]);

        $cardIds = $products->where('has_variants', true)->pluck('id')->all();
        $variants = $cardIds === [] ? collect() : DB::table('product_variants')
            ->whereIn('product_id', $cardIds)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'product_id', 'is_active', 'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price', 'display_old_price'])
            ->groupBy('product_id');

        /** @var array<string, int> $exact "product:variantKey:group" => kopecks */
        $exact = [];

        foreach (DB::table('product_price_tiers')->where('min_qty', 1)->whereIn('product_id', $productIds)->whereIn('customer_group_id', $groupIds)->get(['product_id', 'variant_key', 'customer_group_id', 'price']) as $row) {
            $exact[$this->key((int) $row->product_id, (int) $row->variant_key, (int) $row->customer_group_id)] = Money::fromRubles((string) $row->price);
        }

        /** @var array<string, object> $existing */
        $existing = [];

        foreach (DB::table('product_group_prices_final')->whereIn('product_id', $productIds)->whereIn('customer_group_id', $groupIds)->get(['id', 'product_id', 'variant_key', 'customer_group_id', 'final_price', 'display_old_price']) as $row) {
            $existing[$this->key((int) $row->product_id, (int) $row->variant_key, (int) $row->customer_group_id)] = $row;
        }

        $wanted = [];

        foreach ($products as $product) {
            if ($product->deleted_at !== null) {
                continue;
            }

            $categoryId = (int) $product->category_id;
            $path = $categoryId > 0 ? $this->categories->selfAndAncestorIds($categoryId) : [];

            if (! $product->has_variants) {
                $this->collect($wanted, $product->id, null, PriceInput::fromProduct($product, $path), $product->retailPriceKopecks(), $product->retailOldPriceKopecks(), $contexts, $exact, $rules, $config, $now);

                continue;
            }

            /** @var Collection<int, object> $own */
            $own = $variants->get($product->id, collect());

            if ($own->isEmpty()) {
                continue;
            }

            $active = $own->filter(fn (object $row): bool => (bool) $row->is_active);
            $pool = $active->isNotEmpty() ? $active : $own;
            $best = [];

            foreach ($own as $row) {
                $variant = (new ProductVariant)->forceFill([
                    'base_price' => $row->base_price,
                    'cost_price' => $row->cost_price,
                    'compare_at_price' => $row->compare_at_price,
                    'fixed_price' => $row->fixed_price,
                ]);
                $retail = Money::fromRubles((string) $row->final_price);
                $retailOld = $row->display_old_price !== null ? Money::fromRubles((string) $row->display_old_price) : null;
                $prices = $this->collect($wanted, $product->id, (int) $row->id, PriceInput::fromVariant($variant, $product, $path), $retail, $retailOld, $contexts, $exact, $rules, $config, $now);

                if (! $pool->contains('id', $row->id)) {
                    continue;
                }

                foreach ($prices as $groupId => [$price, $old]) {
                    if (! isset($best[$groupId]) || $price < $best[$groupId][0]) {
                        $best[$groupId] = [$price, $old];
                    }
                }
            }

            $cardPrice = $product->retailPriceKopecks();
            $cardOld = $product->retailOldPriceKopecks();

            foreach ($best as $groupId => [$price, $old]) {
                if ($price !== $cardPrice || $old !== $cardOld) {
                    $wanted[$this->key($product->id, 0, $groupId)] = [
                        'product_id' => $product->id, 'variant_id' => null, 'variant_key' => 0,
                        'customer_group_id' => $groupId, 'price' => $price, 'old' => $old,
                    ];
                }
            }
        }

        return $this->write($wanted, $existing, $now);
    }

    /**
     * Group prices of one item; adds the rows that differ from retail to `$wanted` and returns every group's price.
     *
     * @param  array<string, array<string, mixed>>  $wanted
     * @param  array<int, GroupPriceContext>  $contexts
     * @param  array<string, int>  $exact
     * @param  list<PriceRuleData>  $rules
     * @return array<int, array{int, int|null}> group id => [price, old price]
     */
    private function collect(array &$wanted, int $productId, ?int $variantId, PriceInput $input, int $retail, ?int $retailOld, array $contexts, array $exact, array $rules, PricingConfig $config, CarbonInterface $now): array
    {
        $prices = [];

        foreach ($contexts as $groupId => $context) {
            $exactPrice = $exact[$this->key($productId, $variantId ?? 0, $groupId)] ?? null;

            if ($exactPrice !== null) {
                $price = $exactPrice;
                $old = null;
            } else {
                $result = $this->calculator->calculate($input, $rules, $now, $config, $context);
                $price = $result->finalPrice;
                $old = $result->displayOldPrice;
            }

            $prices[$groupId] = [$price, $old];

            if ($price !== $retail || $old !== $retailOld) {
                $wanted[$this->key($productId, $variantId ?? 0, $groupId)] = [
                    'product_id' => $productId, 'variant_id' => $variantId, 'variant_key' => $variantId ?? 0,
                    'customer_group_id' => $groupId, 'price' => $price, 'old' => $old,
                ];
            }
        }

        return $prices;
    }

    /**
     * Upsert the new and changed rows, delete the rows that are not wanted anymore (batches of 500).
     *
     * @param  array<string, array<string, mixed>>  $wanted
     * @param  array<string, object>  $existing
     */
    private function write(array $wanted, array $existing, CarbonInterface $now): int
    {
        $changed = 0;
        $upserts = [];

        foreach ($wanted as $key => $row) {
            $final = Money::toRubles((int) $row['price']);
            $old = $row['old'] !== null ? Money::toRubles((int) $row['old']) : null;
            $stored = $existing[$key] ?? null;

            if ($stored !== null && Money::fromRubles((string) $stored->final_price) === (int) $row['price']
                && ($stored->display_old_price !== null ? Money::fromRubles((string) $stored->display_old_price) : null) === ($row['old'] !== null ? (int) $row['old'] : null)) {
                continue;
            }

            $upserts[] = [
                'product_id' => $row['product_id'],
                'variant_id' => $row['variant_id'],
                'variant_key' => $row['variant_key'],
                'customer_group_id' => $row['customer_group_id'],
                'final_price' => $final,
                'display_old_price' => $old,
                'computed_at' => $now,
            ];
        }

        foreach (array_chunk($upserts, self::BATCH_SIZE) as $batch) {
            DB::table('product_group_prices_final')->upsert(
                $batch,
                ['product_id', 'variant_key', 'customer_group_id'],
                ['variant_id', 'final_price', 'display_old_price', 'computed_at'],
            );
            $changed += count($batch);
        }

        $obsolete = [];

        foreach ($existing as $key => $row) {
            if (! isset($wanted[$key])) {
                $obsolete[] = (int) $row->id;
            }
        }

        foreach (array_chunk($obsolete, self::BATCH_SIZE) as $batch) {
            DB::table('product_group_prices_final')->whereIn('id', $batch)->delete();
            $changed += count($batch);
        }

        return $changed;
    }

    private function key(int $productId, int $variantKey, int $groupId): string
    {
        return $productId.':'.$variantKey.':'.$groupId;
    }
}
