<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Customers\InnValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Company requisite check (CA-10, CG-20), see {@see InnValidator::isValidOgrn()}. */
class Ogrn implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! app(InnValidator::class)->isValidOgrn((string) $value)) {
            $fail('requisites.validation.ogrn')->translate();
        }
    }
}
