<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Models\Cart;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartSummary;

/**
 * The promo code of a cart (PM-07, PM-13, PM-23) on top of {@see PromoService}: reads the lines of the cart page,
 * evaluates the stored code, applies a typed / cookie code and removes it. One code per cart: applying a second one
 * replaces the first ({@see PromoApplyOutcome::$replaced} tells the caller to warn the customer).
 *
 * `carts.promo_applied_manually` means the customer has taken the code into their own hands (typed it or removed it
 * by hand): an auto-applied code from the `shop_promo` cookie is only attempted while the cart has no code and the flag
 * is off.
 */
final class PromoCartService
{
    public function __construct(
        private readonly PromoService $promos,
        private readonly ViewerContext $viewer,
    ) {}

    /**
     * The rows of the cart page the code can work on (unavailable rows are left out).
     *
     * @return list<PromoLine>
     */
    public function lines(CartSummary $summary): array
    {
        $lines = [];

        foreach ($summary->lines as $line) {
            $promoLine = PromoLine::fromCartLine($line);

            if ($promoLine !== null) {
                $lines[] = $promoLine;
            }
        }

        return $lines;
    }

    public function viewer(?string $email = null, ?string $phone = null): PromoViewer
    {
        return PromoViewer::fromContext($this->viewer, $email, $phone);
    }

    /**
     * The stored code of the cart evaluated against the current lines; null when the cart has no code. A code that no
     * longer applies comes back as a failed result (the cart keeps it, the customer sees the reason).
     */
    public function evaluate(Cart $cart, CartSummary $summary): ?PromoResult
    {
        if ($cart->promo_code === null || $cart->promo_code === '') {
            return null;
        }

        return $this->promos->evaluate($this->lines($summary), $cart->promo_code, $this->viewer());
    }

    /**
     * The customer types a code: validated now, stored only when it applies (a stored code is replaced, PM-07).
     */
    public function apply(Cart $cart, CartSummary $summary, string $input): PromoApplyOutcome
    {
        $result = $this->promos->evaluate($this->lines($summary), $input, $this->viewer());
        $replaced = null;

        if ($result->isApplied() && $result->code !== null) {
            $previous = $cart->promo_code;
            $replaced = $previous !== null && $previous !== '' && $previous !== $result->code->code ? $previous : null;
            $cart->promo_code = $result->code->code;
            $cart->promo_applied_manually = true;
            $cart->save();
        }

        return new PromoApplyOutcome($result, $replaced);
    }

    /**
     * PM-23: tries the code of the `shop_promo` cookie. Nothing is stored when the cart already has a code, the customer
     * has handled the code themselves or the code does not apply now (the result carries the reason for the hint).
     * Returns null when no attempt was made.
     */
    public function autoApply(Cart $cart, CartSummary $summary, ?string $cookieCode): ?PromoResult
    {
        if ($cookieCode === null || trim($cookieCode) === '' || ($cart->promo_code !== null && $cart->promo_code !== '') || $cart->promo_applied_manually) {
            return null;
        }

        $result = $this->promos->evaluate($this->lines($summary), $cookieCode, $this->viewer());

        if ($result->isApplied() && $result->code !== null) {
            $cart->promo_code = $result->code->code;
            $cart->save();
        }

        return $result;
    }

    /**
     * The «убрать» button: the code leaves the cart and is not auto-applied again.
     */
    public function remove(Cart $cart): void
    {
        $cart->promo_code = null;
        $cart->promo_applied_manually = true;
        $cart->save();
    }
}
