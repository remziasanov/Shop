<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceSource;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Backend of the admin editor «Цены для групп» of a product or a variant (GP-17): the rows of every group for the form,
 * validation of the submitted rows with errors and warnings (GP-14), the hint «без точной цены» (step 3 of 21.2), the
 * price preview for a quantity and the save through {@see PriceTierService}.
 *
 * The state of the form is keyed by `g<group id>` (a plain key, usable as a form state path): `['g5' => [['min_qty' => 10,
 * 'price' => '900.00']]]`.
 */
final class GroupPriceEditor
{
    public function __construct(
        private readonly PriceTierService $tiers,
        private readonly GroupPriceRecalculator $groupPrices,
    ) {}

    /**
     * Every group (also inactive and system ones: GP-02 allows any group), in the order of the groups list.
     *
     * @return Collection<int, CustomerGroup>
     */
    public function groups(): Collection
    {
        return CustomerGroup::query()->ordered()->get();
    }

    public static function key(CustomerGroup|int $group): string
    {
        return 'g'.($group instanceof CustomerGroup ? $group->id : $group);
    }

    /**
     * Group id of a state key, null when the key is not one of the editor.
     */
    public static function groupIdOf(string $key): ?int
    {
        return preg_match('/^g(\d+)$/', $key, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * The saved rows of the item for the form, by state key.
     *
     * @return array<string, list<array{min_qty: int, price: string}>>
     */
    public function state(Product|ProductVariant $item): array
    {
        $state = [];

        foreach ($this->groups() as $group) {
            $state[self::key($group)] = $this->tiers->rows($item, $group);
        }

        return $state;
    }

    /**
     * Whether the item can have group prices of its own: a card with variants has them on its variants (VR-20).
     */
    public function editable(Product|ProductVariant $item): bool
    {
        return $item instanceof ProductVariant || ! $item->has_variants;
    }

    /**
     * Validate the submitted rows of every group.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, TierValidation> by state key; groups that do not exist are skipped
     */
    public function validate(Product|ProductVariant $item, array $state): array
    {
        $result = [];
        $groups = $this->groups()->keyBy('id');

        foreach ($state as $key => $rows) {
            $group = $groups->get((int) self::groupIdOf((string) $key));

            if ($group === null) {
                continue;
            }

            $result[(string) $key] = $this->tiers->validate($item, $group, $this->rowsOf($rows));
        }

        return $result;
    }

    /**
     * Save the rows of the groups in the state (a group that is not in the state is left alone) and recalculate the
     * group prices of the item once.
     *
     * @param  array<string, mixed>  $state
     * @return list<string> warnings «Группа: …» of the saved sets
     *
     * @throws ValidationException when a set is invalid; nothing is saved then
     */
    public function save(Product|ProductVariant $item, array $state): array
    {
        $validations = $this->validate($item, $state);
        $messages = [];

        foreach ($validations as $key => $validation) {
            if (! $validation->passes()) {
                $messages[$key] = $validation->errorMessages();
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        $groups = $this->groups()->keyBy('id');
        $warnings = [];
        $changedGroups = [];

        foreach ($validations as $key => $validation) {
            $group = $groups->get((int) self::groupIdOf($key));

            if ($group === null) {
                continue;
            }

            $result = $this->tiers->replace($item, $group, $this->rowsOf($state[$key]), recalculate: false);

            if ($result->changed()) {
                $changedGroups[] = $group->id;
            }

            foreach ($result->warnings as $issue) {
                $warnings[] = $group->name.': '.$issue->message;
            }
        }

        if ($changedGroups !== []) {
            $this->groupPrices->recalculate([$item instanceof ProductVariant ? $item->product_id : $item->id], $changedGroups);
        }

        return $warnings;
    }

    /**
     * Price of the group without an exact price: the retail price with the rules of the group and its discount.
     */
    public function withoutExactPrice(Product|ProductVariant $item, CustomerGroup $group): int
    {
        return $this->groupPrices->ruleBasedPrice($item, $group)->finalPrice;
    }

    /**
     * The unit price the form rows would give for a quantity (GP-03 order of the sources), without saving them.
     * Null when the rows are invalid.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{price: int, source: PriceSource, tier_min_qty: int|null}|null
     */
    public function preview(Product|ProductVariant $item, CustomerGroup $group, array $rows, int $qty): ?array
    {
        $validation = $this->tiers->validate($item, $group, $rows);

        if (! $validation->passes()) {
            return null;
        }

        $qty = max(1, $qty);
        $matched = null;
        $exact = null;

        foreach ($validation->rows as $row) {
            if ($row['min_qty'] === 1) {
                $exact = $row['price'];
            } elseif ($row['min_qty'] <= $qty) {
                $matched = $row;
            }
        }

        if ($matched !== null) {
            return ['price' => $matched['price'], 'source' => PriceSource::Tier, 'tier_min_qty' => $matched['min_qty']];
        }

        if ($exact !== null) {
            return ['price' => $exact, 'source' => PriceSource::GroupPrice, 'tier_min_qty' => null];
        }

        $price = $this->withoutExactPrice($item, $group);
        $discounted = $price !== $item->retailPriceKopecks() || bccomp((string) $group->default_discount_percent, '0', 2) > 0;

        return ['price' => $price, 'source' => $discounted ? PriceSource::GroupDiscount : PriceSource::Retail, 'tier_min_qty' => null];
    }

    /**
     * «900 ₽ за шт (ступень от 10 шт)» for the preview line of the form.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function previewLine(Product|ProductVariant $item, CustomerGroup $group, array $rows, mixed $qty): string
    {
        $quantity = is_numeric($qty) ? (int) $qty : 0;

        if ($quantity < 1) {
            return '';
        }

        $preview = $this->preview($item, $group, $rows, $quantity);

        if ($preview === null) {
            return __('admin_group_prices.editor.preview_invalid');
        }

        return __('admin_group_prices.editor.preview_line', [
            'qty' => $quantity,
            'price' => Money::format($preview['price']),
            'total' => Money::format($preview['price'] * $quantity),
            'source' => $preview['source'] === PriceSource::Tier
                ? __('admin_group_prices.editor.source_tier', ['qty' => $preview['tier_min_qty']])
                : $preview['source']->label(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
