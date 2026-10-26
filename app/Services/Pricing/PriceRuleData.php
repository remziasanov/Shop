<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\ProductFlag;
use App\Models\PriceRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Immutable snapshot of a price rule with its targets; what the calculator works with (no Eloquent).
 */
final readonly class PriceRuleData
{
    /**
     * @param  string  $value  decimal string: percent or rubles, depending on the action
     * @param  list<int>  $categoryIds
     * @param  list<int>  $productIds
     * @param  list<string>  $brands  lowercase
     * @param  list<ProductFlag>  $flags
     */
    public function __construct(
        public int $id,
        public string $name,
        public PriceRuleAction $action,
        public string $value,
        public PriceRuleScope $scope = PriceRuleScope::All,
        public bool $includeSubcategories = true,
        public int $priority = 0,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public bool $showAsDiscount = false,
        public bool $stopProcessing = false,
        public array $categoryIds = [],
        public array $productIds = [],
        public array $brands = [],
        public array $flags = [],
    ) {}

    /**
     * Reads targets through the relation: eager load `targets` to avoid extra queries.
     */
    public static function fromModel(PriceRule $rule): self
    {
        return new self(
            id: $rule->id,
            name: $rule->name,
            action: $rule->action,
            value: (string) $rule->value,
            scope: $rule->scope,
            includeSubcategories: $rule->include_subcategories,
            priority: $rule->priority,
            startsAt: $rule->starts_at !== null ? CarbonImmutable::instance($rule->starts_at) : null,
            endsAt: $rule->ends_at !== null ? CarbonImmutable::instance($rule->ends_at) : null,
            showAsDiscount: $rule->show_as_discount,
            stopProcessing: $rule->stop_processing,
            categoryIds: $rule->categoryIds(),
            productIds: $rule->productIds(),
            brands: self::normalizeBrands($rule->brands()),
            flags: $rule->flags(),
        );
    }

    /**
     * Brand names are compared case-insensitively, ignoring surrounding spaces.
     */
    public static function normalizeBrand(string $brand): string
    {
        return mb_strtolower(trim($brand));
    }

    /**
     * Lowercase, trimmed, unique, non-empty.
     *
     * @param  list<string>  $brands
     * @return list<string>
     */
    private static function normalizeBrands(array $brands): array
    {
        return array_values(array_unique(array_filter(array_map(self::normalizeBrand(...), $brands), fn (string $b): bool => $b !== '')));
    }

    /**
     * Period check: starts_at <= now and now < ends_at (either bound optional).
     */
    public function isInEffect(CarbonInterface $now): bool
    {
        return ($this->startsAt === null || $this->startsAt->lte($now))
            && ($this->endsAt === null || $this->endsAt->gt($now));
    }

    /**
     * Whether the product is inside the rule's scope.
     */
    public function matches(PriceInput $input): bool
    {
        return match ($this->scope) {
            PriceRuleScope::All => true,
            PriceRuleScope::Categories => $this->matchesCategory($input),
            PriceRuleScope::Products => $input->productId !== null && in_array($input->productId, $this->productIds, true),
            PriceRuleScope::Brands => $input->brand !== null && in_array(self::normalizeBrand($input->brand), $this->brands, true),
            PriceRuleScope::Flags => $this->matchesFlags($input),
        };
    }

    private function matchesCategory(PriceInput $input): bool
    {
        if ($input->categoryId !== null && in_array($input->categoryId, $this->categoryIds, true)) {
            return true;
        }

        return $this->includeSubcategories && array_intersect($input->categoryIds(), $this->categoryIds) !== [];
    }

    private function matchesFlags(PriceInput $input): bool
    {
        foreach ($this->flags as $flag) {
            if (($flag === ProductFlag::Featured && $input->isFeatured) || ($flag === ProductFlag::New && $input->isNew)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plain-array form for the cache (survives class changes between deploys, unlike serialized objects).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'action' => $this->action->value,
            'value' => $this->value,
            'scope' => $this->scope->value,
            'include_subcategories' => $this->includeSubcategories,
            'priority' => $this->priority,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'ends_at' => $this->endsAt?->toIso8601String(),
            'show_as_discount' => $this->showAsDiscount,
            'stop_processing' => $this->stopProcessing,
            'category_ids' => $this->categoryIds,
            'product_ids' => $this->productIds,
            'brands' => $this->brands,
            'flags' => array_map(fn (ProductFlag $flag): string => $flag->value, $this->flags),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<int> $categoryIds */
        $categoryIds = $data['category_ids'];
        /** @var list<int> $productIds */
        $productIds = $data['product_ids'];
        /** @var list<string> $brands */
        $brands = $data['brands'];
        /** @var list<string> $flags */
        $flags = $data['flags'];

        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            action: PriceRuleAction::from((string) $data['action']),
            value: (string) $data['value'],
            scope: PriceRuleScope::from((string) $data['scope']),
            includeSubcategories: (bool) $data['include_subcategories'],
            priority: (int) $data['priority'],
            startsAt: $data['starts_at'] !== null ? CarbonImmutable::parse((string) $data['starts_at']) : null,
            endsAt: $data['ends_at'] !== null ? CarbonImmutable::parse((string) $data['ends_at']) : null,
            showAsDiscount: (bool) $data['show_as_discount'],
            stopProcessing: (bool) $data['stop_processing'],
            categoryIds: $categoryIds,
            productIds: $productIds,
            brands: $brands,
            flags: array_map(fn (string $flag): ProductFlag => ProductFlag::from($flag), $flags),
        );
    }
}
