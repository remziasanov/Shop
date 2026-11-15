<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens on a provider failure or when the daily limit is reached (PV-05): the verification is skipped
 * (the request goes on unverified) or blocked.
 */
enum PhoneVerificationBehavior: string
{
    case Skip = 'skip';
    case Block = 'block';

    public function label(): string
    {
        return __('phone_verification.behavior.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
