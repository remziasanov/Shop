<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

/**
 * SEC-05: the new address of an e-mail change plus the current password.
 */
class ChangeEmailRequest extends EmailRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + ['email_current_password' => ['required', 'string', 'max:255']];
    }
}
