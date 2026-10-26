<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\PriceRuleTargetType;
use App\Enums\ProductFlag;
use App\Models\Category;
use App\Models\PriceRule;
use App\Models\PriceRuleTarget;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an active +10 % markup on all products. Scope states create their targets after creating the rule.
 *
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
{
    protected $model = PriceRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'is_active' => true,
            'action' => PriceRuleAction::MarkupPercent,
            'value' => '10.0000',
            'scope' => PriceRuleScope::All,
            'include_subcategories' => true,
            'priority' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'show_as_discount' => false,
            'stop_processing' => false,
        ];
    }

    public function action(PriceRuleAction $action, string|int|float $value): static
    {
        return $this->state(fn (): array => ['action' => $action, 'value' => (string) $value]);
    }

    public function markupPercent(string|int|float $value = 10): static
    {
        return $this->action(PriceRuleAction::MarkupPercent, $value);
    }

    public function markupFixed(string|int|float $value = 100): static
    {
        return $this->action(PriceRuleAction::MarkupFixed, $value);
    }

    public function costMarkupPercent(string|int|float $value = 30): static
    {
        return $this->action(PriceRuleAction::CostMarkupPercent, $value);
    }

    public function discountPercent(string|int|float $value = 10): static
    {
        return $this->action(PriceRuleAction::DiscountPercent, $value);
    }

    public function discountFixed(string|int|float $value = 100): static
    {
        return $this->action(PriceRuleAction::DiscountFixed, $value);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function showAsDiscount(): static
    {
        return $this->state(fn (): array => ['show_as_discount' => true]);
    }

    public function stopProcessing(): static
    {
        return $this->state(fn (): array => ['stop_processing' => true]);
    }

    public function between(CarbonInterface|string|null $startsAt, CarbonInterface|string|null $endsAt): static
    {
        return $this->state(fn (): array => ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    public function forAll(): static
    {
        return $this->state(fn (): array => ['scope' => PriceRuleScope::All]);
    }

    /**
     * @param  iterable<Category|int>  $categories
     */
    public function forCategories(iterable $categories, bool $includeSubcategories = true): static
    {
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = $category instanceof Category ? $category->id : $category;
        }

        return $this
            ->state(fn (): array => ['scope' => PriceRuleScope::Categories, 'include_subcategories' => $includeSubcategories])
            ->withTargets(array_map(fn (int $id): array => ['target_type' => PriceRuleTargetType::Category, 'target_id' => $id], $ids));
    }

    /**
     * @param  iterable<Product|int>  $products
     */
    public function forProducts(iterable $products): static
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = $product instanceof Product ? $product->id : $product;
        }

        return $this
            ->state(fn (): array => ['scope' => PriceRuleScope::Products])
            ->withTargets(array_map(fn (int $id): array => ['target_type' => PriceRuleTargetType::Product, 'target_id' => $id], $ids));
    }

    /**
     * @param  list<string>  $brands
     */
    public function forBrands(array $brands): static
    {
        return $this
            ->state(fn (): array => ['scope' => PriceRuleScope::Brands])
            ->withTargets(array_map(fn (string $brand): array => ['target_type' => PriceRuleTargetType::Brand, 'target_value' => $brand], $brands));
    }

    /**
     * @param  list<ProductFlag>  $flags
     */
    public function forFlags(array $flags): static
    {
        return $this
            ->state(fn (): array => ['scope' => PriceRuleScope::Flags])
            ->withTargets(array_map(fn (ProductFlag $flag): array => ['target_type' => PriceRuleTargetType::Flag, 'target_value' => $flag->value], $flags));
    }

    /**
     * Create the given target rows (attributes of {@see PriceRuleTarget}) after the rule is created.
     *
     * @param  list<array<string, mixed>>  $targets
     */
    public function withTargets(array $targets): static
    {
        return $this->afterCreating(function (PriceRule $rule) use ($targets): void {
            foreach ($targets as $target) {
                $rule->targets()->create($target);
            }

            $rule->unsetRelation('targets');
        });
    }
}
