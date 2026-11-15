<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\PhoneVerificationDriver;
use Spatie\LaravelSettings\Settings;

/**
 * «Настройки → Подтверждение телефона» (PV-01, D-110). The provider login and API key are stored encrypted and never
 * reach a form, a log or a queued job. The scenario fields (PV-07) are read by the integration (PV-13).
 * Enumerations are stored as strings of {@see PhoneVerificationDriver} and its siblings.
 */
class PhoneVerificationSettings extends Settings
{
    public bool $enabled;

    /** `sms_aero` | `log` (PhoneVerificationDriver); `log` is refused in production. */
    public string $driver;

    /** Secret, encrypted. Never seeded, never shown. */
    public ?string $provider_login;

    /** Secret, encrypted. Never seeded, never shown. */
    public ?string $provider_api_key;

    /** `flash_call` | `sms` | `flash_call_then_sms` (PhoneVerificationMode). */
    public string $mode;

    /** Sender name of the SMS (up to 11 Latin characters) registered at the provider. */
    public ?string $sms_sender;

    /** Placeholders {code} and {shop}; the rendered text must fit 70 characters (PV-04). */
    public string $sms_template;

    /** PV-07 registration: `off` | `required` | `later` (PhoneVerificationRegistrationMode). */
    public string $registration;

    /** PV-07: a changed phone number in the profile is verified. */
    public bool $profile_change;

    /** PV-07: a wholesale application is verified. */
    public bool $wholesale;

    /** PV-07 guest checkout: `off` | `all` | `reservations` | `orders_over` (PhoneVerificationGuestScope). */
    public string $guest_checkout;

    /** Kopecks: «только заказы от суммы» compares the «order» part of the cart with this amount. */
    public int $guest_checkout_min_amount;

    /** @var list<string> Allowed number prefixes in the canonical form, e.g. `+79` (PV-06). */
    public array $allowed_prefixes;

    /** Daily limit of the shop: provider-accepted requests per day (PV-06). */
    public int $daily_limit;

    /** `block` (default) | `skip` when the daily limit is reached (PhoneVerificationBehavior). */
    public string $on_daily_limit;

    /** `skip` (default) | `block` on a provider failure (PhoneVerificationBehavior). */
    public string $on_provider_failure;

    /** Kopecks: the owner is warned when the provider balance falls below this amount (PV-09). */
    public int $balance_threshold;

    public int $code_ttl_minutes;

    /** Read-only: the last balance seen by the daily check, a decimal string in roubles. */
    public ?string $last_balance;

    /** Read-only: ISO 8601 time of the last balance check. */
    public ?string $last_balance_at;

    /** Read-only: failure kind of the last balance check, null = it worked. */
    public ?string $last_balance_error;

    /**
     * Saves only the given properties after re-reading the settings, so a value written meanwhile (a balance check in the
     * scheduler) is not overwritten by a stale copy.
     *
     * @param  array<string, mixed>  $fields  property => new value
     */
    public function saveOnly(array $fields): void
    {
        $this->refresh();

        foreach ($fields as $property => $value) {
            $this->{$property} = $value;
        }

        $this->save();
    }

    /**
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return ['provider_login', 'provider_api_key'];
    }

    public static function group(): string
    {
        return 'phone_verification';
    }
}
