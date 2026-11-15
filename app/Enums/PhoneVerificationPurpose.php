<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a phone verification is for (PV-07). A verified number is remembered per purpose (24 hours, in the session).
 * `test` is the «Отправить тест на мой номер» button of the settings page (PV-01).
 */
enum PhoneVerificationPurpose: string
{
    case Registration = 'registration';
    case ProfileChange = 'profile_change';
    case Wholesale = 'wholesale';
    case GuestCheckout = 'guest_checkout';
    case Test = 'test';

    public function label(): string
    {
        return __('phone_verification.purposes.'.$this->value);
    }
}
