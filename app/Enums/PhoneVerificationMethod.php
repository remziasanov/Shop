<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a code reached the number (PV-08 `method`).
 */
enum PhoneVerificationMethod: string
{
    case FlashCall = 'flash_call';
    case Sms = 'sms';

    public function label(): string
    {
        return __('phone_verification.methods.'.$this->value);
    }
}
