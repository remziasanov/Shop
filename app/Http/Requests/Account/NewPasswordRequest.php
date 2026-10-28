<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\CustomerPassword;
use App\Services\Customers\CustomerAuthService;

/**
 * The new password after a verified reset code (CA-07).
 */
class NewPasswordRequest extends AccountRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'confirmed', ...CustomerPassword::min(), new CustomerPassword($this->resetEmail())]];
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

    private function resetEmail(): ?string
    {
        return $this->hasSession() ? app(CustomerAuthService::class)->resetTicketEmail($this->session()) : null;
    }
}
