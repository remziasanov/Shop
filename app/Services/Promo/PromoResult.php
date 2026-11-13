<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoFailure;
use App\Models\PromoCode;

/**
 * Outcome of {@see PromoService::evaluate()} (PM-08). Money is integer kopecks. When `failure` is set the code does not
 * apply: `message` is the Russian text for the customer (PM-09) and the discounts are empty. `lineDiscounts` maps the
 * key of a {@see PromoLine} to its discount; their sum is exactly `discount`.
 */
final readonly class PromoResult
{
    /**
     * @param  array<int|string, int>  $lineDiscounts
     * @param  list<string>  $reasons
     */
    public function __construct(
        public ?PromoCode $code,
        public ?PromoFailure $failure,
        public ?string $message,
        public array $lineDiscounts,
        public int $discount,
        public bool $freeDelivery,
        public array $reasons = [],
    ) {}

    public static function failed(?PromoCode $code, PromoFailure $failure, string $message): self
    {
        return new self($code, $failure, $message, [], 0, false, [$message]);
    }

    /**
     * @param  array<int|string, int>  $lineDiscounts
     */
    public static function applied(PromoCode $code, array $lineDiscounts, bool $freeDelivery): self
    {
        return new self($code, null, null, $lineDiscounts, array_sum($lineDiscounts), $freeDelivery);
    }

    public function isApplied(): bool
    {
        return $this->failure === null;
    }

    public function discountFor(int|string $key): int
    {
        return $this->lineDiscounts[$key] ?? 0;
    }
}
