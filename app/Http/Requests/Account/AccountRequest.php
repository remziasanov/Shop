<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Base of the customer-account forms: open to everyone (the routes carry the guards), the e-mail is trimmed and
 * lower-cased before validation so lookups, limits and codes see one spelling of an address.
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
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
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
