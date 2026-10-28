<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Models\Customer;
use App\Rules\CustomerPassword;

/**
 * CA-08: a new password needs the current one.
 */
class ChangePasswordRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $customer = $this->user('customer');

        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', ...CustomerPassword::min(), new CustomerPassword($customer instanceof Customer ? $customer->email : null)],
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
