<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a one-time e-mail code is for (CA-04, CA-25). Stored as a string in `customer_verification_codes.purpose`.
 */
enum VerificationPurpose: string
{
    case Register = 'register';
    case Login = 'login';
    case PasswordReset = 'password_reset';
    case ChangeEmail = 'change_email';
    case DeleteAccount = 'delete_account';

    public function label(): string
    {
        return __('account.purposes.'.$this->value);
    }
}
