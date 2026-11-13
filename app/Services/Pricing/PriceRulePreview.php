<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Settings\PricingSettings;
use App\Support\Money;

/**
 * Preview of an unsaved price rule (PR-04): how many products it touches and the first 20 with the
 * current price and the price after the rule. "After" is calculated with the active rules without the
 * edited rule's saved version, plus the draft (treated as enabled, its period ignored so that a future
 * rule still shows what it will do).
 */
final class PriceRulePreview
{
    public const LIMIT = 20;

    public function __construct(
        private readonly RuleProductMatcher $matcher,
        private readonly PriceRuleRepository $rules,
        private readonly PriceCalculator $calculator,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * With a customer group (GP-04) the prices are those of the group: the rules «для всех» and the rules bound to the
     * group, then the group discount; "before" is the same calculation without the draft. Without one they are retail prices.
     *
     * @return array{count: int, rows: list<array{id: int, name: string, sku: string, before: int, after: int}>}
     */
    public function preview(PriceRuleData $draft, ?CustomerGroup $group = null): array
    {
        $ids = $this->matcher->productIds($draft)->take(self::LIMIT)->all();
        $count = $this->matcher->count($draft);

        if ($ids === []) {
            return ['count' => $count, 'rows' => []];
        }

        $others = array_values(array_filter(
            $this->rules->activeRules(),
            fn (PriceRuleData $rule): bool => $rule->id !== $draft->id,
        ));
        $rules = [...$others, $this->withoutPeriod($draft)];

        $settings = app(PricingSettings::class);
        $config = PricingConfig::fromSettings($settings);
        $context = $group !== null ? GroupPriceContext::for($group, $settings) : null;
        $now = now();
        $rows = [];

        $products = Product::query()->whereIn('id', $ids)->orderBy('id')->get([
            'id', 'name', 'sku', 'category_id', 'brand', 'is_featured', 'is_new',
            'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price',
        ]);

        foreach ($products as $product) {
            $categoryId = (int) $product->category_id;
            $input = PriceInput::fromProduct($product, $categoryId > 0 ? $this->categories->selfAndAncestorIds($categoryId) : []);

            $rows[] = [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'before' => $context === null
                    ? Money::fromRubles($product->final_price)
                    : $this->calculator->calculate($input, $others, $now, $config, $context)->finalPrice,
                'after' => $this->calculator->calculate($input, $rules, $now, $config, $context)->finalPrice,
            ];
        }

        return ['count' => $count, 'rows' => $rows];
    }

    private function withoutPeriod(PriceRuleData $rule): PriceRuleData
    {
        return new PriceRuleData(
            id: $rule->id,
            name: $rule->name,
            action: $rule->action,
            value: $rule->value,
            scope: $rule->scope,
            includeSubcategories: $rule->includeSubcategories,
            priority: $rule->priority,
            startsAt: null,
            endsAt: null,
            showAsDiscount: $rule->showAsDiscount,
            stopProcessing: $rule->stopProcessing,
            categoryIds: $rule->categoryIds,
            productIds: $rule->productIds,
            brands: $rule->brands,
            flags: $rule->flags,
            groupIds: $rule->groupIds,
        );
    }
}
