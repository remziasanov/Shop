<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * PV-01, PV-04..PV-07: the phone verification settings. Off by default, every scenario off, no secrets seeded.
 */
return new class extends SettingsMigration
{
    private const NAMES = [
        'enabled', 'driver', 'provider_login', 'provider_api_key', 'mode', 'sms_sender', 'sms_template', 'registration',
        'profile_change', 'wholesale', 'guest_checkout', 'guest_checkout_min_amount', 'allowed_prefixes', 'daily_limit',
        'on_daily_limit', 'on_provider_failure', 'balance_threshold', 'code_ttl_minutes', 'last_balance', 'last_balance_at',
        'last_balance_error',
    ];

    public function up(): void
    {
        $this->migrator->add('phone_verification.enabled', false);
        $this->migrator->add('phone_verification.driver', 'sms_aero');
        $this->migrator->addEncrypted('phone_verification.provider_login', null);
        $this->migrator->addEncrypted('phone_verification.provider_api_key', null);
        $this->migrator->add('phone_verification.mode', 'flash_call');
        $this->migrator->add('phone_verification.sms_sender', null);
        $this->migrator->add('phone_verification.sms_template', 'Код подтверждения: {code}. {shop}');
        $this->migrator->add('phone_verification.registration', 'off');
        $this->migrator->add('phone_verification.profile_change', false);
        $this->migrator->add('phone_verification.wholesale', false);
        $this->migrator->add('phone_verification.guest_checkout', 'off');
        // 5 000 ₽ in kopecks: the example threshold of PV-07.
        $this->migrator->add('phone_verification.guest_checkout_min_amount', 500000);
        $this->migrator->add('phone_verification.allowed_prefixes', ['+79']);
        $this->migrator->add('phone_verification.daily_limit', 200);
        $this->migrator->add('phone_verification.on_daily_limit', 'block');
        $this->migrator->add('phone_verification.on_provider_failure', 'skip');
        // 200 ₽ in kopecks.
        $this->migrator->add('phone_verification.balance_threshold', 20000);
        $this->migrator->add('phone_verification.code_ttl_minutes', 5);
        $this->migrator->add('phone_verification.last_balance', null);
        $this->migrator->add('phone_verification.last_balance_at', null);
        $this->migrator->add('phone_verification.last_balance_error', null);
    }

    public function down(): void
    {
        foreach (self::NAMES as $name) {
            $this->migrator->delete('phone_verification.'.$name);
        }
    }
};
