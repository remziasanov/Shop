<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\PriceRuleTargetType;
use App\Enums\ProductFlag;
use App\Models\PriceRule;
use App\Rules\PositiveAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saves a price rule from the admin form state together with its targets (one transaction), then asks
 * {@see PriceRuleRecalculator} to recalculate the affected products (PR-02).
 *
 * Form state keys: name, is_active, action, value, scope, include_subcategories, priority, starts_at, ends_at,
 * show_as_discount, stop_processing, category_ids, product_ids, brands, flags. Only the targets that belong
 * to the chosen scope are stored.
 */
final class PriceRuleSaver
{
    public function __construct(private readonly PriceRuleRecalculator $recalculator) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function save(?PriceRule $rule, array $state): PriceRule
    {
        $normalized = $this->normalize($state);
        $before = $rule?->exists ? PriceRuleData::fromModel($rule->loadMissing('targets')) : null;

        $rule = DB::transaction(function () use ($rule, $normalized): PriceRule {
            $rule ??= new PriceRule;

            $rule->fill([
                'name' => $normalized['name'],
                'is_active' => $normalized['is_active'],
                'action' => $normalized['action'],
                'value' => $normalized['value'],
                'scope' => $normalized['scope'],
                'include_subcategories' => $normalized['include_subcategories'],
                'priority' => $normalized['priority'],
                'starts_at' => $normalized['starts_at'],
                'ends_at' => $normalized['ends_at'],
                'show_as_discount' => $normalized['show_as_discount'],
                'stop_processing' => $normalized['stop_processing'],
            ]);
            $rule->save();

            $rule->targets()->delete();

            foreach ($this->targetRows($normalized) as $row) {
                $rule->targets()->create($row);
            }

            return $rule;
        });

        $rule->unsetRelation('targets');
        $rule->load('targets');

        $this->recalculator->ruleChanged($before, PriceRuleData::fromModel($rule));

        return $rule;
    }

    public function delete(PriceRule $rule): void
    {
        $before = PriceRuleData::fromModel($rule->loadMissing('targets'));

        $rule->delete();

        $this->recalculator->ruleChanged($before, null);
    }

    /**
     * After a rule was enabled or disabled outside of {@see save()} (the table toggle).
     */
    public function toggled(PriceRule $rule): void
    {
        $data = PriceRuleData::fromModel($rule->loadMissing('targets'));

        $this->recalculator->ruleChanged($data, $data);
    }

    /**
     * Snapshot of the form state for previews. Rule id 0 means "not saved yet" (previews of new rules pass PHP_INT_MAX: the newest id sorts last).
     *
     * @param  array<string, mixed>  $state
     *
     * @throws InvalidArgumentException when the state is incomplete or invalid
     */
    public function toData(array $state, int $id = 0): PriceRuleData
    {
        $n = $this->normalize($state);

        return new PriceRuleData(
            id: $id,
            name: $n['name'],
            action: $n['action'],
            value: $n['value'],
            scope: $n['scope'],
            includeSubcategories: $n['include_subcategories'],
            priority: $n['priority'],
            startsAt: $n['starts_at'] !== null ? CarbonImmutable::parse($n['starts_at']) : null,
            endsAt: $n['ends_at'] !== null ? CarbonImmutable::parse($n['ends_at']) : null,
            showAsDiscount: $n['show_as_discount'],
            stopProcessing: $n['stop_processing'],
            categoryIds: $n['category_ids'],
            productIds: $n['product_ids'],
            brands: array_map(PriceRuleData::normalizeBrand(...), $n['brands']),
            flags: $n['flags'],
        );
    }

    /**
     * Form state of an existing rule (attributes + target lists) for filling the form.
     *
     * @return array<string, mixed>
     */
    public function formState(PriceRule $rule): array
    {
        $rule->loadMissing('targets');

        $value = (string) $rule->value;

        return [
            'name' => $rule->name,
            'is_active' => $rule->is_active,
            'action' => $rule->action->value,
            'value' => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value,
            'scope' => $rule->scope->value,
            'include_subcategories' => $rule->include_subcategories,
            'priority' => $rule->priority,
            'starts_at' => $rule->starts_at,
            'ends_at' => $rule->ends_at,
            'show_as_discount' => $rule->show_as_discount,
            'stop_processing' => $rule->stop_processing,
            'category_ids' => $rule->categoryIds(),
            'product_ids' => $rule->productIds(),
            'brands' => $rule->brands(),
            'flags' => array_map(fn (ProductFlag $flag): string => $flag->value, $rule->flags()),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{name: string, is_active: bool, action: PriceRuleAction, value: string, scope: PriceRuleScope, include_subcategories: bool, priority: int, starts_at: ?string, ends_at: ?string, show_as_discount: bool, stop_processing: bool, category_ids: list<int>, product_ids: list<int>, brands: list<string>, flags: list<ProductFlag>}
     */
    private function normalize(array $state): array
    {
        $action = PriceRuleAction::tryFrom((string) ($state['action'] ?? ''));
        $scope = PriceRuleScope::tryFrom((string) ($state['scope'] ?? ''));
        $number = PositiveAmount::normalize($state['value'] ?? null);

        if ($action === null || $scope === null || $number === null || bccomp($number, '0', 6) <= 0) {
            throw new InvalidArgumentException('Incomplete price rule.');
        }

        $filled = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : null);

        $ids = static fn (mixed $v): array => array_values(array_unique(array_map('intval', is_array($v) ? $v : [])));

        $brands = [];
        foreach (is_array($state['brands'] ?? null) ? $state['brands'] : [] as $brand) {
            $brand = trim((string) $brand);
            $brands[mb_strtolower($brand)] = $brand;
        }
        unset($brands['']);

        $flags = [];
        foreach (is_array($state['flags'] ?? null) ? $state['flags'] : [] as $flag) {
            $case = ProductFlag::tryFrom((string) $flag);
            if ($case !== null && ! in_array($case, $flags, true)) {
                $flags[] = $case;
            }
        }

        return [
            'name' => trim((string) ($state['name'] ?? '')),
            'is_active' => (bool) ($state['is_active'] ?? true),
            'action' => $action,
            'value' => bcadd($number, '0', 4),
            'scope' => $scope,
            'include_subcategories' => (bool) ($state['include_subcategories'] ?? true),
            'priority' => (int) ($state['priority'] ?? 0),
            'starts_at' => $filled($state['starts_at'] ?? null),
            'ends_at' => $filled($state['ends_at'] ?? null),
            'show_as_discount' => $action->isDiscount() && (bool) ($state['show_as_discount'] ?? false),
            'stop_processing' => (bool) ($state['stop_processing'] ?? false),
            'category_ids' => $scope === PriceRuleScope::Categories ? $ids($state['category_ids'] ?? []) : [],
            'product_ids' => $scope === PriceRuleScope::Products ? $ids($state['product_ids'] ?? []) : [],
            'brands' => $scope === PriceRuleScope::Brands ? array_values($brands) : [],
            'flags' => $scope === PriceRuleScope::Flags ? $flags : [],
        ];
    }

    /**
     * @param  array{category_ids: list<int>, product_ids: list<int>, brands: list<string>, flags: list<ProductFlag>}  $normalized
     * @return list<array<string, mixed>>
     */
    private function targetRows(array $normalized): array
    {
        $rows = [];

        foreach ($normalized['category_ids'] as $id) {
            $rows[] = ['target_type' => PriceRuleTargetType::Category, 'target_id' => $id];
        }

        foreach ($normalized['product_ids'] as $id) {
            $rows[] = ['target_type' => PriceRuleTargetType::Product, 'target_id' => $id];
        }

        foreach ($normalized['brands'] as $brand) {
            $rows[] = ['target_type' => PriceRuleTargetType::Brand, 'target_value' => $brand];
        }

        foreach ($normalized['flags'] as $flag) {
            $rows[] = ['target_type' => PriceRuleTargetType::Flag, 'target_value' => $flag->value];
        }

        return $rows;
    }
}
