<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provider drivers (PV-02). `log` is for development and tests and cannot be chosen in production.
 */
enum PhoneVerificationDriver: string
{
    case SmsAero = 'sms_aero';
    case Log = 'log';

    public function label(): string
    {
        return __('phone_verification.drivers.'.$this->value);
    }

    /**
     * Whether the driver may be used in the given environment: `log` never in production.
     */
    public function allowedInProduction(): bool
    {
        return $this !== self::Log;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->filter(fn (self $case): bool => ! app()->isProduction() || $case->allowedInProduction())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
