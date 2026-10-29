<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Customers\EmailNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses an e-mail address that has no canonical ASCII spelling (non-Latin local part, malformed domain); see
 * {@see EmailNormalizer}. An address with a national domain is accepted and stored in its punycode form.
 */
class CanonicalEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || EmailNormalizer::normalize($value) === null) {
            $fail('validation.canonical_email')->translate();
        }
    }
}
