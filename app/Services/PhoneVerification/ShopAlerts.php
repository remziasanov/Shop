<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Jobs\SendPhoneVerificationAlert;
use App\Services\Notifications\JobFanOut;
use App\Services\Notifications\TelegramNotifier;
use App\Settings\NotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warnings to the shop about the phone verification provider (PV-05, PV-09): e-mail to the notification addresses and
 * Telegram to the work chat, each channel on its switch of «Настройки → Уведомления». Never more than once per hour per
 * topic (once a day for the guest-share notice). A problem while queueing is logged and never reaches the customer's request.
 */
final class ShopAlerts
{
    public const INTERVAL_SECONDS = 3600;

    public function providerFailure(string $kind): bool
    {
        return $this->send(SendPhoneVerificationAlert::TOPIC_PROVIDER_FAILURE, ['kind' => $kind]);
    }

    public function emptyBalance(): bool
    {
        return $this->send(SendPhoneVerificationAlert::TOPIC_EMPTY_BALANCE);
    }

    public function dailyLimit(int $limit): bool
    {
        return $this->send(SendPhoneVerificationAlert::TOPIC_DAILY_LIMIT, ['limit' => (string) $limit]);
    }

    /**
     * Only the guest share of the daily budget is used up (SEC-01, D-198): a lighter notice, once a day, that does not
     * claim the whole limit is gone.
     */
    public function guestShare(int $guestCap, int $limit): bool
    {
        return $this->send(SendPhoneVerificationAlert::TOPIC_GUEST_SHARE, ['cap' => (string) $guestCap, 'limit' => (string) $limit], 86400);
    }

    /**
     * @param  string  $balance  roubles, decimal string
     * @param  string  $threshold  roubles, decimal string
     */
    public function lowBalance(string $balance, string $threshold): bool
    {
        return $this->send(SendPhoneVerificationAlert::TOPIC_LOW_BALANCE, ['balance' => $balance, 'threshold' => $threshold]);
    }

    /**
     * @param  array<string, string>  $params
     * @return bool true when the warning was queued, false when the hourly limit held it back or nothing is configured
     */
    private function send(string $topic, array $params = [], int $interval = self::INTERVAL_SECONDS): bool
    {
        try {
            // `add` is atomic: of two parallel requests only one sends.
            if (! Cache::add('phone-verification:alert:'.$topic, 1, $interval)) {
                return false;
            }

            $settings = app(NotificationSettings::class);
            /** @var list<ShouldQueue> $jobs */
            $jobs = [];

            if ($settings->notify_shop_email) {
                foreach (array_unique(array_filter($settings->notify_emails)) as $email) {
                    $jobs[] = new SendPhoneVerificationAlert($topic, SendPhoneVerificationAlert::MAIL, $email, $params);
                }
            }

            if ($settings->notify_shop_telegram && app(TelegramNotifier::class)->isEnabled()) {
                $jobs[] = new SendPhoneVerificationAlert($topic, SendPhoneVerificationAlert::TELEGRAM, null, $params);
            }

            return JobFanOut::dispatch($jobs) > 0;
        } catch (Throwable $e) {
            Log::warning('Phone verification alert not queued', ['topic' => $topic, 'exception' => $e::class]);

            return false;
        }
    }
}
