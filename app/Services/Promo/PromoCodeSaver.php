<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoScope;
use App\Enums\PromoTargetType;
use App\Enums\PromoType;
use App\Models\PromoCode;
use App\Models\PromoCodeTarget;
use App\Models\User;
use App\Rules\PositiveAmount;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saves a promo code from the admin form state with its groups and targets in one transaction (PM-01…PM-04) and turns
 * the same state into the template of a code batch (PM-15).
 *
 * Form state keys: code, name, is_active, type, value, scope, include_subcategories, category_ids, product_ids, brands,
 * min_order_amount, starts_at, ends_at, usage_limit, per_customer_limit, only_registered, applies_to_discounted,
 * stackable_with_promotions, group_ids. Only the targets of the chosen scope are stored; an empty group list falls back
 * to «Гость» and «Розница» (PM-04: an empty list is forbidden).
 *
 * @phpstan-import-type Template from PromoBatchService
 */
final class PromoCodeSaver
{
    /** Largest fixed discount / minimum order the form accepts (decimal(12,2) with room to spare). */
    public const MAX_AMOUNT = '99999999.99';

    /**
     * The code with its relations. A code that already has redemptions keeps its text (PM-19).
     *
     * @param  array<string, mixed>  $state
     */
    public function save(?PromoCode $code, array $state, ?User $user = null): PromoCode
    {
        $data = $this->normalize($state);

        return DB::transaction(function () use ($code, $data, $user, $state): PromoCode {
            $creating = $code === null || ! $code->exists;
            $code ??= new PromoCode;

            $text = PromoCodeNormalizer::normalize((string) ($state['code'] ?? ''));

            if ($creating || ! $code->redemptions()->exists()) {
                if (! PromoCodeNormalizer::isValid($text)) {
                    throw new InvalidArgumentException(__('promo_admin.codes.validation.code_format'));
                }

                $code->code = $text;
            }

            $code->fill([
                'name' => trim((string) ($state['name'] ?? '')),
                'is_active' => (bool) ($state['is_active'] ?? true),
                'type' => $data['type'],
                'value' => $data['value'],
                'scope' => $data['scope'],
                'include_subcategories' => $data['include_subcategories'],
                'min_order_amount' => $data['min_order_amount'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'usage_limit' => $this->limit($state['usage_limit'] ?? null),
                'per_customer_limit' => $this->limit($state['per_customer_limit'] ?? null),
                'only_registered' => $data['only_registered'],
                'applies_to_discounted' => $data['applies_to_discounted'],
                'stackable_with_promotions' => $data['stackable_with_promotions'],
            ]);

            if ($creating) {
                $code->created_by = $user?->id;
            }

            $code->save();
            $code->groups()->sync($data['group_ids']);
            $code->targets()->delete();

            foreach ($data['targets'] as $target) {
                $type = PromoTargetType::from($target['type']);
                $code->targets()->create([
                    'target_type' => $type,
                    'target_id' => $type === PromoTargetType::Brand ? null : ($target['id'] ?? null),
                    'target_value' => $type === PromoTargetType::Brand ? ($target['value'] ?? null) : null,
                ]);
            }

            return $code->unsetRelation('targets')->unsetRelation('groups');
        });
    }

    /**
     * A copy of the code under a new text: same conditions, no uses, not part of a batch.
     */
    public function duplicate(PromoCode $source, string $newCode, ?User $user = null): PromoCode
    {
        $state = $this->formState($source);
        $state['code'] = $newCode;
        $state['name'] = $source->name;

        return $this->save(null, $state, $user);
    }

    /**
     * Form state of an existing code (attributes + target lists + groups) for filling the form.
     *
     * @return array<string, mixed>
     */
    public function formState(PromoCode $code): array
    {
        $code->loadMissing(['targets', 'groups']);

        $targets = fn (PromoTargetType $type): array => $code->targets->where('target_type', $type)->values()->all();

        return [
            'code' => $code->code,
            'name' => $code->name,
            'is_active' => $code->is_active,
            'type' => $code->type->value,
            'value' => $this->plain($code->value),
            'scope' => $code->scope->value,
            'include_subcategories' => $code->include_subcategories,
            'category_ids' => array_map(fn (PromoCodeTarget $t): int => (int) $t->target_id, $targets(PromoTargetType::Category)),
            'product_ids' => array_map(fn (PromoCodeTarget $t): int => (int) $t->target_id, $targets(PromoTargetType::Product)),
            'brands' => array_map(fn (PromoCodeTarget $t): string => (string) $t->target_value, $targets(PromoTargetType::Brand)),
            'min_order_amount' => $this->plain($code->min_order_amount),
            'starts_at' => $code->starts_at,
            'ends_at' => $code->ends_at,
            'usage_limit' => $code->usage_limit,
            'per_customer_limit' => $code->per_customer_limit,
            'only_registered' => $code->only_registered,
            'applies_to_discounted' => $code->applies_to_discounted,
            'stackable_with_promotions' => $code->stackable_with_promotions,
            'group_ids' => $code->groups->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * The batch template (PM-15) of a form state: the shared conditions without code, name and usage limits.
     *
     * @param  array<string, mixed>  $state
     * @return Template
     */
    public function templateFromState(array $state): array
    {
        $data = $this->normalize($state);

        return [
            'type' => $data['type']->value,
            'value' => $data['value'],
            'scope' => $data['scope']->value,
            'include_subcategories' => $data['include_subcategories'],
            'min_order_amount' => $data['min_order_amount'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'only_registered' => $data['only_registered'],
            'applies_to_discounted' => $data['applies_to_discounted'],
            'stackable_with_promotions' => $data['stackable_with_promotions'],
            'is_active' => (bool) ($state['is_active'] ?? true),
            'group_ids' => $data['group_ids'],
            'targets' => $data['targets'],
        ];
    }

    /**
     * The inverse of {@see templateFromState()}: form state of a stored template.
     *
     * @param  array<string, mixed>|null  $template
     * @return array<string, mixed>
     */
    public function templateToState(?array $template): array
    {
        $template ??= [];
        $targets = is_array($template['targets'] ?? null) ? $template['targets'] : [];
        $ids = fn (string $type): array => array_values(array_map(
            fn (array $t): int => (int) ($t['id'] ?? 0),
            array_filter($targets, fn (mixed $t): bool => is_array($t) && ($t['type'] ?? null) === $type),
        ));

        return [
            'type' => $template['type'] ?? PromoType::Percent->value,
            'value' => $this->plain(isset($template['value']) ? (string) $template['value'] : null),
            'scope' => $template['scope'] ?? PromoScope::All->value,
            'include_subcategories' => (bool) ($template['include_subcategories'] ?? true),
            'category_ids' => $ids('category'),
            'product_ids' => $ids('product'),
            'brands' => array_values(array_map(
                fn (array $t): string => (string) ($t['value'] ?? ''),
                array_filter($targets, fn (mixed $t): bool => is_array($t) && ($t['type'] ?? null) === 'brand'),
            )),
            'min_order_amount' => $this->plain(isset($template['min_order_amount']) ? (string) $template['min_order_amount'] : null),
            'starts_at' => $template['starts_at'] ?? null,
            'ends_at' => $template['ends_at'] ?? null,
            'only_registered' => (bool) ($template['only_registered'] ?? false),
            'applies_to_discounted' => (bool) ($template['applies_to_discounted'] ?? false),
            'stackable_with_promotions' => (bool) ($template['stackable_with_promotions'] ?? false),
            'is_active' => (bool) ($template['is_active'] ?? true),
            'group_ids' => array_map('intval', $template['group_ids'] ?? []) ?: PromoCode::defaultGroupIds(),
        ];
    }

    /**
     * PM-02, 22.8: whether the form state changes the discount (type, value, scope or its targets) of a code.
     *
     * @param  array<string, mixed>  $state
     */
    public function discountChanged(PromoCode $code, array $state): bool
    {
        $new = $this->normalize($state);
        $old = $this->normalize($this->formState($code));

        $key = fn (array $data): string => (string) json_encode([
            $data['type']->value, $data['value'], $data['scope']->value,
            $data['scope'] === PromoScope::Categories ? $data['include_subcategories'] : null,
            $data['targets'],
        ]);

        return $key($new) !== $key($old);
    }

    /**
     * Lang key (under `promo_admin.codes.validation`) of the problem with the value for the type, or null when valid.
     */
    public function valueProblem(?string $type, mixed $value): ?string
    {
        $case = $type === null ? null : PromoType::tryFrom($type);

        if ($case === null || ! $case->hasValue()) {
            return null;
        }

        $number = PositiveAmount::normalize($value);

        if ($number === null) {
            return 'value_number';
        }

        if (strlen(rtrim(explode('.', $number.'.')[1], '0')) > 2) {
            return 'value_decimals';
        }

        if ($case === PromoType::Percent) {
            return bccomp($number, '1', 4) < 0 || bccomp($number, '100', 4) > 0 ? 'percent_range' : null;
        }

        if (bccomp($number, '0', 4) <= 0) {
            return 'fixed_positive';
        }

        return bccomp($number, self::MAX_AMOUNT, 4) > 0 ? 'value_max' : null;
    }

    /**
     * Whether another code (not `$ignore`) already has this text, case ignored (PM-01).
     */
    public function codeTaken(string $input, ?PromoCode $ignore = null): bool
    {
        return PromoCode::query()
            ->where('code', PromoCodeNormalizer::normalize($input))
            ->when($ignore?->exists, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{type: PromoType, value: ?string, scope: PromoScope, include_subcategories: bool, min_order_amount: ?string, starts_at: ?string, ends_at: ?string, only_registered: bool, applies_to_discounted: bool, stackable_with_promotions: bool, group_ids: list<int>, targets: list<array{type: string, id?: int, value?: string}>}
     */
    private function normalize(array $state): array
    {
        $type = PromoType::tryFrom((string) ($state['type'] ?? ''));
        $scope = PromoScope::tryFrom((string) ($state['scope'] ?? PromoScope::All->value));

        if ($type === null || $scope === null) {
            throw new InvalidArgumentException('Incomplete promo code.');
        }

        $value = null;

        if ($type->hasValue()) {
            if ($this->valueProblem($type->value, $state['value'] ?? null) !== null) {
                throw new InvalidArgumentException(__('promo_admin.codes.validation.'.$this->valueProblem($type->value, $state['value'] ?? null)));
            }

            $value = bcadd((string) PositiveAmount::normalize($state['value']), '0', 2);
        }

        $minOrder = filled($state['min_order_amount'] ?? null) ? Money::toRubles(Money::fromRubles((string) $state['min_order_amount'])) : null;
        $groups = array_values(array_unique(array_map('intval', is_array($state['group_ids'] ?? null) ? $state['group_ids'] : [])));

        return [
            'type' => $type,
            'value' => $value,
            'scope' => $scope,
            'include_subcategories' => $scope === PromoScope::Categories && (bool) ($state['include_subcategories'] ?? true),
            'min_order_amount' => $minOrder === null || bccomp($minOrder, '0', 2) <= 0 ? null : $minOrder,
            'starts_at' => $this->date($state['starts_at'] ?? null),
            'ends_at' => $this->date($state['ends_at'] ?? null),
            'only_registered' => (bool) ($state['only_registered'] ?? false),
            'applies_to_discounted' => (bool) ($state['applies_to_discounted'] ?? false),
            'stackable_with_promotions' => (bool) ($state['stackable_with_promotions'] ?? false),
            'group_ids' => $groups === [] ? PromoCode::defaultGroupIds() : $groups,
            'targets' => $this->targets($scope, $state),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{type: string, id?: int, value?: string}>
     */
    private function targets(PromoScope $scope, array $state): array
    {
        $rows = [];

        switch ($scope) {
            case PromoScope::Categories:
            case PromoScope::Products:
                $type = ($scope === PromoScope::Categories ? PromoTargetType::Category : PromoTargetType::Product)->value;
                $source = $scope === PromoScope::Categories ? 'category_ids' : 'product_ids';
                $ids = array_values(array_unique(array_map('intval', is_array($state[$source] ?? null) ? $state[$source] : [])));
                sort($ids);

                foreach ($ids as $id) {
                    $rows[] = ['type' => $type, 'id' => $id];
                }

                break;

            case PromoScope::Brands:
                $brands = [];

                foreach (is_array($state['brands'] ?? null) ? $state['brands'] : [] as $brand) {
                    $brand = trim((string) $brand);

                    if ($brand !== '') {
                        $brands[mb_strtolower($brand)] = $brand;
                    }
                }

                foreach ($brands as $brand) {
                    $rows[] = ['type' => 'brand', 'value' => $brand];
                }

                break;

            case PromoScope::All:
                break;
        }

        return $rows;
    }

    private function limit(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function date(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_string($value) && trim($value) !== '' => date('Y-m-d H:i:s', (int) strtotime($value)),
            default => null,
        };
    }

    /**
     * "10.00" => "10", "12.50" => "12.5" for form fields.
     */
    private function plain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
