<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\CanonicalEmail;

/**
 * CA-06: sign-in by e-mail and password.
 */
class LoginRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:190', new CanonicalEmail, 'email:rfc'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $attributes */
        $attributes = trans('account.fields');

        return $attributes;
    }
}
