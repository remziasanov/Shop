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
        }
    }
}
