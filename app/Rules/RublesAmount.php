<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * A non-negative rubles amount as typed by a person: "1 290,50", "1290.5", "1 290 ₽".
 */
class RublesAmount implements ValidationRule
{
    /** Largest value of a decimal(12,2) column, in kopecks. */
    public const DEFAULT_MAX_KOPECKS = 999_999_999_999;

    /**
     * @param  int  $maxKopecks  upper bound, in kopecks (default: decimal(12,2) maximum, 9 999 999 999.99 ₽)
     */
    public function __construct(private readonly int $maxKopecks = self::DEFAULT_MAX_KOPECKS) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $kopecks = Money::fromRubles(is_scalar($value) ? $value : '');
        } catch (InvalidArgumentException) {
            $fail('shop.validation.rubles_amount')->translate();

            return;
        }

        if ($kopecks < 0) {
            $fail('shop.validation.rubles_amount')->translate();

            return;
        }

        if ($kopecks > $this->maxKopecks) {
            $fail('shop.validation.rubles_amount_max')->translate(['max' => Money::format($this->maxKopecks)]);
        }
    }
}
