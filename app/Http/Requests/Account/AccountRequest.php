<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\CanonicalEmail;
use App\Services\Customers\EmailNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the customer-account forms: open to everyone (the routes carry the guards), the e-mail is reduced to its
 * canonical ASCII form ({@see EmailNormalizer}) before validation, so lookups, limits and codes see one spelling of an
 * address; an address that has none fails the {@see CanonicalEmail} rule.
 */
abstract class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => EmailNormalizer::normalizeOrLower((string) $this->input('email'))]);
        }
    }

    /**
     * The validated e-mail address (lower case).
     */
    public function email(): string
    {
        return (string) $this->validated('email');
    }
}
