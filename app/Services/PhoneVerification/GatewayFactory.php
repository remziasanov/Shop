<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\PhoneVerificationDriver;
use App\Settings\PhoneVerificationSettings;
use RuntimeException;

/**
 * Builds the driver chosen in the settings (PV-02). The log driver is refused in production.
 */
final class GatewayFactory
{
    public static function forSettings(PhoneVerificationSettings $settings): PhoneVerificationGateway
    {
        $driver = PhoneVerificationDriver::tryFrom($settings->driver) ?? PhoneVerificationDriver::SmsAero;

        if (! self::isAllowed($driver)) {
            throw new RuntimeException('The log driver of phone verification cannot be used in production.');
        }

        return match ($driver) {
            PhoneVerificationDriver::SmsAero => new SmsAeroGateway((string) $settings->provider_login, (string) $settings->provider_api_key, (string) $settings->sms_sender),
            PhoneVerificationDriver::Log => new LogGateway,
        };
    }

    public static function isAllowed(PhoneVerificationDriver $driver): bool
    {
        return ! app()->isProduction() || $driver->allowedInProduction();
    }
}
