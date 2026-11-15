<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;
use App\Settings\PhoneVerificationSettings;
use Illuminate\Support\Facades\Log;

/**
 * Provider balance (PV-01 «Проверить подключение», PV-09 daily check): asks the provider, remembers the answer for
 * shop:doctor and warns the shop when the balance is below the owner's threshold. The warning goes at most once an hour
 * ({@see ShopAlerts}), the daily schedule makes it a daily reminder.
 */
final class BalanceMonitor
{
    public function __construct(
        private readonly PhoneVerificationSettings $settings,
        private readonly ShopAlerts $alerts,
        private readonly PhoneVerificationService $service,
    ) {}

    /**
     * Asks for the balance and stores it. Does not warn: this is the «Проверить подключение» button.
     *
     * @return string|null roubles as a decimal string, null when the driver cannot tell
     *
     * @throws PhoneProviderFailure
     * @throws PhoneProviderRefusal
     */
    public function refresh(): ?string
    {
        try {
            $balance = app(PhoneVerificationGateway::class)->balance();
        } catch (PhoneProviderFailure $e) {
            $this->settings->saveOnly(['last_balance_error' => $e->kind->value, 'last_balance_at' => now()->toIso8601String()]);

            throw $e;
        }

        $this->settings->saveOnly(['last_balance' => $balance, 'last_balance_at' => now()->toIso8601String(), 'last_balance_error' => null]);

        return $balance;
    }

    /**
     * The scheduled daily check. Returns the balance (null: nothing to check or unknown).
     */
    public function check(): ?string
    {
        if (! $this->service->isEnabled()) {
            return null;
        }

        try {
            $balance = $this->refresh();
        } catch (PhoneProviderFailure|PhoneProviderRefusal $e) {
            Log::warning('Phone verification balance check failed', ['message' => $e->getMessage()]);

            if ($e instanceof PhoneProviderFailure) {
                $this->alerts->providerFailure($e->kind->value);
            }

            return null;
        }

        if ($balance !== null && bccomp($balance, $this->thresholdRoubles(), 2) < 0) {
            $this->alerts->lowBalance($balance, $this->thresholdRoubles());
        }

        return $balance;
    }

    /**
     * The threshold in roubles as a decimal string (it is stored in kopecks).
     */
    public function thresholdRoubles(): string
    {
        return bcdiv((string) $this->settings->balance_threshold, '100', 2);
    }
}
