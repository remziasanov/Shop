<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Registration scenario of PV-07: off, the phone is required before the customer may place requests, or can be done later.
 */
enum PhoneVerificationRegistrationMode: string
{
    case Off = 'off';
    case Required = 'required';
    case Later = 'later';

    public function label(): string
    {
        return __('phone_verification.registration.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
