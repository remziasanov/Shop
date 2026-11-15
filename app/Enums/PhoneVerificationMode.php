<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Methods the owner allows (PV-01, PV-04): flash call only, SMS only, or a flash call with an SMS on the customer's button.
 */
enum PhoneVerificationMode: string
{
    case FlashCall = 'flash_call';
    case Sms = 'sms';
    case FlashCallThenSms = 'flash_call_then_sms';

    public function label(): string
    {
        return __('phone_verification.modes.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
