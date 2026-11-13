<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoFailure;
use App\Enums\PromoScope;
use App\Enums\PromoTargetType;
use App\Enums\PromoType;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Services\Catalog\CategoryTree;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Promo code calculation (PM-08, PM-09, 22.6): a function over the rows of a cart. The only things it reads from the
 * database are the code with its groups / targets and the redemptions of the customer (per-customer limit); it changes
 * nothing. Usage is counted by {@see PromoRedemptionService}. Money is integer kopecks, shares are computed with bcmath.
 *
 * Per row: `regular` is unit price × qty (after group / tier price and promotions). A row without `promoOldPrice` is
 * discounted from `regular`. A row on promotion is skipped unless the code applies to discounted items (PM-05); then
 * with «суммируется» the discount is taken from `regular`, without it the code price is computed from the pre-promotion
 * total and the buyer pays the smaller of that and `regular` (PM-06, «лучшая цена»).
 */
final class PromoService
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * @param  list<PromoLine>  $lines  the purchasable rows of the cart (or of the «order» part of a mixed cart)
     * @param  PromoCode|string|null  $code  a code row or the text typed by the customer
     */
    public function evaluate(array $lines, PromoCode|string|null $code, PromoViewer $viewer, ?CarbonInterface $now = null): PromoResult
    {
        $now ??= now();

        if (is_string($code)) {
            $code = PromoCode::findByCode($code);
        }

        // PM-09: an unknown code and an inactive one read the same, nothing about existing codes is revealed.
        if ($code === null) {
            return PromoResult::failed(null, PromoFailure::NotFound, __('promo.messages.not_found'));
        }

        if (! $code->is_active) {
            return PromoResult::failed($code, PromoFailure::Inactive, __('promo.messages.not_found'));
        }

        if ($code->starts_at !== null && $code->starts_at->gt($now)) {
            return PromoResult::failed($code, PromoFailure::NotStarted, __('promo.messages.not_started', [
                'date' => $code->starts_at->format($code->starts_at->year === $now->year ? 'd.m' : 'd.m.Y'),
            ]));
        }

        if ($code->ends_at !== null && $code->ends_at->lt($now)) {
            return PromoResult::failed($code, PromoFailure::Expired, __('promo.messages.expired'));
        }

        if ($viewer->groupId === null || ! $code->groups()->where('customer_groups.id', $viewer->groupId)->exists()) {
            return PromoResult::failed($code, PromoFailure::GroupNotAllowed, __('promo.messages.group_not_allowed'));
        }

        if ($code->only_registered && ! $viewer->isRegistered()) {
            return PromoResult::failed($code, PromoFailure::OnlyRegistered, __('promo.messages.only_registered'));
        }

        if ($code->usage_limit !== null && $code->usage_count >= $code->usage_limit) {
            return PromoResult::failed($code, PromoFailure::Exhausted, __('promo.messages.exhausted'));
        }

        if ($code->per_customer_limit !== null && $this->usedBy($code, $viewer) >= $code->per_customer_limit) {
            return PromoResult::failed($code, PromoFailure::PerCustomerLimit, __('promo.messages.per_customer_limit'));
        }

        $orderTotal = array_sum(array_map(fn (PromoLine $line): int => $line->regularTotal(), $lines));
        $min = $code->minOrderKopecks();

        if ($min !== null && $min > 0 && $orderTotal < $min) {
            return PromoResult::failed($code, PromoFailure::MinOrder, __('promo.messages.min_order', ['min' => Money::format($min)]));
        }

        return $this->price($lines, $code);
    }

    /**
     * OE-06: the promo code of an order on its edited rows. The code is not «claimed» again, so the period, the limits,
     * the group, «только для зарегистрированных» and the minimum amount are not checked; the scope and the promotion
     * flags (PM-05, PM-06) apply as at checkout and a fixed amount never exceeds the base of the rows. An order without
     * a code (or whose code was deleted) gets an empty result. A code that no longer takes anything off reads as failed
     * (the discount is then zero). Changes nothing in the database.
     *
     * @param  list<PromoLine>  $lines  the rows of the order after the edit
     */
    public function recalculateForOrder(Order $order, array $lines): PromoResult
    {
        $code = $order->promo_code_id === null ? null : PromoCode::query()->find($order->promo_code_id);

        if ($code === null) {
            return new PromoResult(null, null, null, [], 0, false);
        }

        return $this->price($lines, $code);
    }

    /**
     * The rows of the code's scope and the discount of each, once the code itself is accepted.
     *
     * @param  list<PromoLine>  $lines
     */
    private function price(array $lines, PromoCode $code): PromoResult
    {
        $items = $this->items($lines, $code);

        if ($items === []) {
            return PromoResult::failed($code, PromoFailure::NoEligibleItems, __('promo.messages.no_eligible_items'));
        }

        if ($code->type === PromoType::FreeDelivery) {
            return PromoResult::applied($code, [], true);
        }

        $discounts = $this->discounts($items, $code);

        // A percent / fixed code that takes nothing off (the promotion price is already the best) is not «applied»: no use is burnt.
        if (array_sum($discounts) <= 0) {
            return PromoResult::failed($code, PromoFailure::NoDiscount, __('promo.messages.no_discount'));
        }

        return PromoResult::applied($code, $discounts, false);
    }

    /**
     * How many times the customer has used the code, not counting released uses (PM-10): by id for a signed-in
     * customer, by the HMAC of the contact data for a guest (0 while the contact is not known yet).
     */
    public function usedBy(PromoCode $code, PromoViewer $viewer): int
    {
        $query = PromoRedemption::query()->where('promo_code_id', $code->id)->whereNull('released_at');

        if ($viewer->customerId !== null) {
            return $query->where('customer_id', $viewer->customerId)->count();
        }

        $hash = $viewer->customerHash();

        return $hash === null ? 0 : $query->where('customer_hash', $hash)->count();
    }

    /**
     * Rows the code works on with their base (`base`) and the price the buyer cannot be pushed above (`floor`).
     *
     * @param  list<PromoLine>  $lines
     * @return list<array{line: PromoLine, regular: int, base: int, floor: int|null}>
     */
    private function items(array $lines, PromoCode $code): array
    {
        $items = [];
        $inScope = $this->scopeMatcher($code);

        foreach ($lines as $line) {
            if (! $inScope($line)) {
                continue;
            }

            $regular = $line->regularTotal();

            if ($line->promoOldPrice === null) {
                $items[] = ['line' => $line, 'regular' => $regular, 'base' => $regular, 'floor' => null];

                continue;
            }

            if (! $code->applies_to_discounted) {
                continue;
            }

            $items[] = $code->stackable_with_promotions
                ? ['line' => $line, 'regular' => $regular, 'base' => $regular, 'floor' => null]
                : ['line' => $line, 'regular' => $regular, 'base' => $line->promoOldPrice * $line->qty, 'floor' => $regular];
        }

        return $items;
    }

    /**
     * @param  list<array{line: PromoLine, regular: int, base: int, floor: int|null}>  $items
     * @return array<int|string, int>
     */
    private function discounts(array $items, PromoCode $code): array
    {
        $bases = array_map(fn (array $item): int => $item['base'], $items);

        $shares = $code->type === PromoType::Percent
            ? $this->percentShares($bases, Money::fromRubles($code->value))
            : $this->fixedShares($bases, Money::fromRubles($code->value));

        $discounts = [];

        foreach ($items as $i => $item) {
            $after = $item['base'] - $shares[$i];

            if ($item['floor'] !== null) {
                $after = min($after, $item['floor']);
            }

            $discounts[$item['line']->key] = max(0, $item['regular'] - max(0, $after));
        }

        return $discounts;
    }

    /**
     * Percent of every base, half-up to the kopeck. `$hundredths` is the percent × 100 (10.50 % = 1050).
     *
     * @param  list<int>  $bases
     * @return list<int>
     */
    private function percentShares(array $bases, int $hundredths): array
    {
        $hundredths = max(0, min(10000, $hundredths));

        return array_map(
            fn (int $base): int => (int) bcdiv(bcadd(bcmul((string) $base, (string) $hundredths), '5000'), '10000', 0),
            $bases,
        );
    }

    /**
     * A fixed amount (at most the sum of the bases) split in proportion to the bases by the largest remainder method,
     * so that the shares add up to exactly the amount; ties go to the earlier row.
     *
     * @param  list<int>  $bases
     * @return list<int>
     */
    private function fixedShares(array $bases, int $amount): array
    {
        $sum = array_sum($bases);
        $amount = max(0, min($amount, $sum));

        if ($sum === 0 || $amount === 0) {
            return array_fill(0, count($bases), 0);
        }

        $shares = [];
        $remainders = [];
        $given = 0;

        foreach ($bases as $i => $base) {
            $product = bcmul((string) $amount, (string) $base);
            $shares[$i] = (int) bcdiv($product, (string) $sum, 0);
            $remainders[$i] = (int) bcmod($product, (string) $sum);
            $given += $shares[$i];
        }

        $order = array_keys($bases);
        usort($order, fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

        for ($left = $amount - $given, $k = 0; $left > 0; $left--, $k++) {
            $shares[$order[$k]]++;
        }

        return $shares;
    }

    /**
     * PM-03: a test for "is the row inside the scope of the code"; the id sets are built once per evaluation
     * (categories with their subtrees via the cached tree when «включая подкатегории» is on).
     *
     * @return callable(PromoLine): bool
     */
    private function scopeMatcher(PromoCode $code): callable
    {
        $targets = $code->loadMissing('targets')->targets;

        switch ($code->scope) {
            case PromoScope::Categories:
                $ids = [];

                foreach ($targets->where('target_type', PromoTargetType::Category) as $target) {
                    $id = (int) $target->target_id;
                    $ids = [...$ids, ...($code->include_subcategories ? $this->tree->descendantIds($id) : [$id])];
                }

                return fn (PromoLine $line): bool => $line->categoryId !== null && in_array($line->categoryId, $ids, true);

            case PromoScope::Products:
                $ids = $targets->where('target_type', PromoTargetType::Product)->pluck('target_id')->map(fn ($id): int => (int) $id)->all();

                return fn (PromoLine $line): bool => in_array($line->productId, $ids, true);

            case PromoScope::Brands:
                $brands = $targets->where('target_type', PromoTargetType::Brand)->map(fn ($target): string => mb_strtolower(trim((string) $target->target_value)))->values()->all();

                return fn (PromoLine $line): bool => $line->brand !== null && in_array(mb_strtolower(trim($line->brand)), $brands, true);

            default:
                return fn (PromoLine $line): bool => true;
        }
    }
}
