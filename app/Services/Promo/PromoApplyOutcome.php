<?php

declare(strict_types=1);

namespace App\Services\Promo;

/**
 * Result of typing a code into the cart: the evaluation and the code it replaced, if any (PM-07 warning).
 */
final readonly class PromoApplyOutcome
{
    public function __construct(
        public PromoResult $result,
        public ?string $replaced = null,
    ) {}
}
