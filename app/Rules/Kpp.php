<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Customers\InnValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Company requisite check (CA-10, CG-20), see {@see InnValidator::isValidKpp()}. */
class Kpp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! app(InnValidator::class)->isValidKpp((string) $value)) {
            $fail('requisites.validation.kpp')->translate();
        }
    }
}
