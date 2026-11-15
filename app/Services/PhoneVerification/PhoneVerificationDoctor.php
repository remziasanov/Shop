<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\CheckStatus;
use App\Enums\PhoneVerificationDriver;
use App\Services\System\CheckResult;
use App\Settings\PhoneVerificationSettings;
use Carbon\CarbonImmutable;

/**
 * The phone verification line of `shop:doctor` and the «Система» page (PV-02, PV-09): driver, the log driver in production
 * (an error), missing credentials and the last balance seen by the daily check. A shop that does not use the feature gets
 * an «ok» line (NF-63).
 */
final class PhoneVerificationDoctor
{
    public const KEY = 'phone_verification';

    public function __construct(private readonly PhoneVerificationSettings $settings, private readonly BalanceMonitor $balance) {}

    public function check(): CheckResult
    {
        $driver = PhoneVerificationDriver::tryFrom($this->settings->driver) ?? PhoneVerificationDriver::SmsAero;

        if (! GatewayFactory::isAllowed($driver)) {
            return $this->result(CheckStatus::Error, __('phone_verification.doctor.log_in_production'), __('phone_verification.doctor.hints.log_in_production'));
        }

        if (! $this->settings->enabled) {
            return $this->result(CheckStatus::Ok, __('phone_verification.doctor.disabled', ['driver' => $driver->label()]));
        }

        if ($driver === PhoneVerificationDriver::SmsAero && (blank($this->settings->provider_login) || blank($this->settings->provider_api_key))) {
            return $this->result(CheckStatus::Error, __('phone_verification.doctor.no_credentials', ['driver' => $driver->label()]), __('phone_verification.doctor.hints.no_credentials'));
        }

        $label = $driver->label();

        if ($this->settings->last_balance_error !== null) {
            return $this->result(CheckStatus::Warning, __('phone_verification.doctor.balance_error', ['driver' => $label, 'kind' => $this->settings->last_balance_error]), __('phone_verification.doctor.hints.balance_error'));
        }

        $balance = $this->settings->last_balance;

        if ($balance === null) {
            return $this->result(CheckStatus::Ok, __('phone_verification.doctor.no_balance', ['driver' => $label]));
        }

        $at = $this->settings->last_balance_at !== null ? CarbonImmutable::parse($this->settings->last_balance_at)->format('d.m.Y H:i') : '—';
        $value = __('phone_verification.doctor.balance', ['driver' => $label, 'balance' => $balance, 'time' => $at]);

        return bccomp($balance, $this->balance->thresholdRoubles(), 2) < 0
            ? $this->result(CheckStatus::Warning, $value, __('phone_verification.doctor.hints.low_balance'))
            : $this->result(CheckStatus::Ok, $value);
    }

    private function result(CheckStatus $status, string $value, ?string $hint = null): CheckResult
    {
        return new CheckResult(self::KEY, __('system.checks.'.self::KEY), $status, $value, $hint);
    }
}
