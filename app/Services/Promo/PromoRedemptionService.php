<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoFailure;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\PromoRedemption;
use App\Settings\PromoSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Usage accounting of promo codes (PM-11, PM-12). Every method runs inside the caller's transaction (or opens one) and
 * takes the `promo_codes` row lock LAST, after the order, the products and the variants (19.5, 22.6).
 */
final class PromoRedemptionService
{
    public function __construct(
        private readonly PromoService $promos,
        private readonly PromoSettings $settings,
    ) {}

    /**
     * Checkout step (PM-11): re-reads the code under `FOR UPDATE`, validates it again completely and takes one use with a
     * conditional UPDATE. A failed result means no order may be created; nothing was changed in that case. Call
     * {@see record()} once the order exists.
     *
     * @param  list<PromoLine>  $lines  the rows as priced inside the checkout transaction
     */
    public function claim(string $codeText, array $lines, PromoViewer $viewer): PromoResult
    {
        $code = PromoCode::query()
            ->where('code', PromoCodeNormalizer::normalize($codeText))
            ->lockForUpdate()
            ->first();

        $result = $this->promos->evaluate($lines, $code, $viewer);

        if (! $result->isApplied() || $code === null) {
            return $result;
        }

        $affected = DB::table('promo_codes')
            ->where('id', $code->id)
            ->where(fn ($query) => $query->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))
            ->increment('usage_count');

        if ($affected === 0) {
            return PromoResult::failed($code, PromoFailure::Exhausted, __('promo.messages.exhausted'));
        }

        return $result;
    }

    /**
     * The redemption of an order (unique per order); the discount amount is the total of the result.
     */
    public function record(Order $order, PromoResult $result, PromoViewer $viewer): PromoRedemption
    {
        $code = $result->code ?? throw new LogicException('A redemption needs an applied code.');

        return PromoRedemption::query()->create([
            'promo_code_id' => $code->id,
            'order_id' => $order->id,
            'customer_id' => $viewer->customerId,
            'customer_hash' => $viewer->customerHash(),
            'discount_amount' => Money::toRubles($result->discount),
        ]);
    }

    /**
     * PM-12: gives the use of a cancelled / expired order back — `usage_count` goes down and `released_at` is filled,
     * once (a released redemption is never released again). Does nothing when the order has no promo code or the
     * setting «Возвращать код при отмене» is off. Call it after the order and its stock were handled, so the lock
     * order stays order, products, variants, promo code.
     *
     * @return bool whether a use was given back
     */
    public function release(Order $order): bool
    {
        if (! $this->settings->release_on_cancel) {
            return false;
        }

        return DB::transaction(function () use ($order): bool {
            $codeId = PromoRedemption::query()->where('order_id', $order->id)->whereNull('released_at')->value('promo_code_id');

            if ($codeId === null) {
                return false;
            }

            PromoCode::query()->whereKey($codeId)->lockForUpdate()->first();

            $redemption = PromoRedemption::query()
                ->where('order_id', $order->id)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if ($redemption === null) {
                return false;
            }

            DB::table('promo_codes')->where('id', $codeId)->where('usage_count', '>', 0)->decrement('usage_count');
            $redemption->released_at = now();
            $redemption->save();

            return true;
        });
    }

    /**
     * PM-24: the link id from the cookie when the link still exists, otherwise null.
     */
    public function validLinkId(?int $linkId): ?int
    {
        return $linkId !== null && PromoLink::query()->whereKey($linkId)->exists() ? $linkId : null;
    }
}
