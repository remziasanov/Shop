<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Concerns;

use App\Enums\CheckoutChoice;
use App\Models\Cart;
use App\Services\Cart\CartService;
use App\Services\Cart\CartSummary;
use App\Services\Promo\PromoAttemptGuard;
use App\Services\Promo\PromoCartService;
use App\Services\Promo\PromoCodeNormalizer;
use App\Services\Promo\PromoResult;

/**
 * The «Есть промокод?» box of the cart page and the checkout (PM-13): typing, replacing and removing the code, the
 * brute-force guard (PM-14) and the auto-application of the `shop_promo` cookie (PM-23). Prices are always re-evaluated
 * on the server from the cart; nothing but the typed text comes from the browser.
 */
trait HandlesPromoCode
{
    public const PROMO_COOKIE = 'shop_promo';

    /** The text of the input. */
    public string $promoInput = '';

    /** Refusal of the last typed code (PM-09) or the «too many attempts» text (PM-14). */
    public ?string $promoError = null;

    /** The warning that a second code replaced the first (PM-07). */
    public ?string $promoNotice = null;

    public function applyPromo(): void
    {
        $this->promoError = null;
        $this->promoNotice = null;

        $input = mb_substr(trim($this->promoInput), 0, 64);

        if ($input === '') {
            $this->promoError = __('promo.messages.empty');

            return;
        }

        $carts = app(CartService::class);
        $cart = $carts->current(request());

        if ($cart === null) {
            return;
        }

        $guard = app(PromoAttemptGuard::class);
        $ip = (string) request()->ip();
        $session = (string) session()->getId();
        $wait = $guard->retryAfter($ip, $session);

        if ($wait > 0) {
            $this->promoError = $guard->message($wait);

            return;
        }

        $guard->hit($ip, $session);
        $outcome = app(PromoCartService::class)->apply($cart, $carts->summary($cart, $this->summaryChoice()), $input);

        if ($outcome->result->isApplied()) {
            $guard->succeeded($ip, $session);
            $this->promoInput = '';

            if ($outcome->replaced !== null && $outcome->result->code !== null) {
                $this->promoNotice = __('promo.messages.replaced', ['old' => $outcome->replaced, 'new' => $outcome->result->code->code]);
            }
        } else {
            $guard->failed($ip, $session);
            $this->promoError = $outcome->result->message ?? __('promo.messages.not_found');
        }

        $this->dispatch('cart-updated');
    }

    /**
     * MR-04: the choice for the `flexible` rows the promo code is evaluated with (the checkout page overrides it).
     */
    protected function summaryChoice(): CheckoutChoice
    {
        return CheckoutChoice::Order;
    }

    public function removePromo(): void
    {
        $this->promoError = null;
        $this->promoNotice = null;
        $cart = app(CartService::class)->current(request());

        if ($cart !== null) {
            app(PromoCartService::class)->remove($cart);
        }

        $this->dispatch('cart-updated');
    }

    /**
     * What the cart page and the checkout show about the code: the stored code evaluated against the current lines
     * (after trying the cookie code, PM-23).
     *
     * @return array{code: string|null, result: PromoResult|null, applied: bool, discount: int, freeDelivery: bool, failure: string|null, hint: string|null}
     */
    protected function promoState(?Cart $cart, ?CartSummary $summary): array
    {
        $state = ['code' => null, 'result' => null, 'applied' => false, 'discount' => 0, 'freeDelivery' => false, 'failure' => null, 'hint' => null];

        if ($cart === null || $summary === null || $summary->isEmpty()) {
            return $state;
        }

        $promos = app(PromoCartService::class);
        $cookie = request()->cookie(self::PROMO_COOKIE);
        $auto = $promos->autoApply($cart, $summary, is_string($cookie) ? mb_substr($cookie, 0, PromoCodeNormalizer::MAX_LENGTH + 20) : null);

        // PM-23: a cookie code that does not apply now is a hint, never an error.
        if ($auto !== null && ! $auto->isApplied() && $auto->code !== null && $auto->message !== null) {
            $state['hint'] = __('promo.box.saved', ['code' => $auto->code->code, 'reason' => $auto->message]);
        }

        $result = $promos->evaluate($cart, $summary);

        if ($result === null) {
            return $state;
        }

        $state['code'] = $cart->promo_code;
        $state['result'] = $result;
        $state['applied'] = $result->isApplied();
        $state['failure'] = $result->message;

        if ($result->isApplied()) {
            $state['discount'] = $result->discount;
            $state['freeDelivery'] = $result->freeDelivery;
        }

        return $state;
    }
}
