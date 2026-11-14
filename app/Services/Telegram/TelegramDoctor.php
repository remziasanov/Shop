<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\CheckStatus;
use App\Services\System\CheckResult;
use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The Telegram checks of `shop:doctor` and the «Система» page (TG-12). While the bot is not connected nothing is
 * reported as a problem: a shop that only uses the work chat behaves as in version 2.0 (NF-63).
 */
class TelegramDoctor
{
    public const KEYS = ['telegram_https', 'telegram_webhook', 'telegram_pending', 'telegram_errors', 'telegram_startapp', 'telegram_work_chat'];

    public const CACHE_KEY = 'telegram.doctor.webhook_info';

    private const CACHE_SECONDS = 60;

    /** @var array<string, mixed>|null */
    private ?array $info = null;

    private ?string $infoError = null;

    private bool $infoLoaded = false;

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramBotSetup $setup,
        private readonly TelegramClient $client,
    ) {}

    public function check(string $key): CheckResult
    {
        return match ($key) {
            'telegram_https' => $this->https(),
            'telegram_webhook' => $this->webhook(),
            'telegram_pending' => $this->pending(),
            'telegram_errors' => $this->errors(),
            'telegram_startapp' => $this->startapp(),
            default => $this->workChat(),
        };
    }

    private function https(): CheckResult
    {
        if (! $this->setup->isConnected()) {
            return $this->notConnected('telegram_https');
        }

        return $this->setup->canConnect()
            ? $this->result('telegram_https', CheckStatus::Ok, __('telegram.doctor.https_ok'))
            : $this->result('telegram_https', CheckStatus::Error, (string) config('app.url'), __('telegram.doctor.hints.https'));
    }

    private function webhook(): CheckResult
    {
        if (! $this->setup->isConnected()) {
            return $this->notConnected('telegram_webhook');
        }

        $info = $this->webhookInfo();

        if ($info === null) {
            return $this->unreachable('telegram_webhook');
        }

        $url = (string) ($info['url'] ?? '');

        if ($url === '') {
            return $this->result('telegram_webhook', CheckStatus::Error, __('telegram.doctor.webhook_missing'), __('telegram.doctor.hints.webhook'));
        }

        return $url === $this->setup->webhookUrl()
            ? $this->result('telegram_webhook', CheckStatus::Ok, __('telegram.doctor.webhook_ok', ['url' => $url]))
            : $this->result('telegram_webhook', CheckStatus::Error, __('telegram.doctor.webhook_wrong_url', ['url' => $url]), __('telegram.doctor.hints.webhook'));
    }

    private function pending(): CheckResult
    {
        if (! $this->setup->isConnected()) {
            return $this->notConnected('telegram_pending');
        }

        $info = $this->webhookInfo();

        if ($info === null) {
            return $this->unreachable('telegram_pending');
        }

        $count = (int) ($info['pending_update_count'] ?? 0);

        return $count < 100
            ? $this->result('telegram_pending', CheckStatus::Ok, __('telegram.doctor.pending', ['count' => $count]))
            : $this->result('telegram_pending', CheckStatus::Warning, __('telegram.doctor.pending', ['count' => $count]), __('telegram.doctor.hints.pending'));
    }

    private function errors(): CheckResult
    {
        if (! $this->setup->isConnected()) {
            return $this->notConnected('telegram_errors');
        }

        $info = $this->webhookInfo();

        if ($info === null) {
            return $this->unreachable('telegram_errors');
        }

        $date = $info['last_error_date'] ?? null;

        if (is_numeric($date) && (int) $date >= now()->subDay()->getTimestamp()) {
            return $this->result('telegram_errors', CheckStatus::Warning, (string) ($info['last_error_message'] ?? ''), __('telegram.doctor.hints.webhook_error'));
        }

        return $this->result('telegram_errors', CheckStatus::Ok, __('telegram.doctor.no_errors'));
    }

    private function startapp(): CheckResult
    {
        if (! $this->setup->isConnected()) {
            return $this->notConnected('telegram_startapp');
        }

        return $this->setup->startappVerified()
            ? $this->result('telegram_startapp', CheckStatus::Ok, __('telegram.doctor.startapp_ok'))
            : $this->result('telegram_startapp', CheckStatus::Warning, __('telegram.doctor.startapp_missing'), __('telegram.doctor.hints.startapp'));
    }

    private function workChat(): CheckResult
    {
        $error = $this->settings->work_chat_error;

        return $error === null || $error === ''
            ? $this->result('telegram_work_chat', CheckStatus::Ok, __('telegram.doctor.work_chat_ok'))
            : $this->result('telegram_work_chat', CheckStatus::Error, $error, __('telegram.doctor.hints.work_chat'));
    }

    /**
     * getWebhookInfo, once per run and cached for a minute (the «Система» page re-runs the checks on every render).
     *
     * @return array<string, mixed>|null null when Telegram did not answer
     */
    private function webhookInfo(): ?array
    {
        if ($this->infoLoaded) {
            return $this->info;
        }

        $this->infoLoaded = true;

        try {
            $this->info = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->client->call('getWebhookInfo'));
        } catch (Throwable $e) {
            $this->infoError = $e->getMessage();
        }

        return $this->info;
    }

    private function unreachable(string $key): CheckResult
    {
        return $this->result($key, CheckStatus::Warning, __('telegram.doctor.api_failed', ['error' => (string) $this->infoError]));
    }

    private function notConnected(string $key): CheckResult
    {
        return $this->result($key, CheckStatus::Ok, __('telegram.doctor.not_connected'));
    }

    private function result(string $key, CheckStatus $status, string $value, ?string $hint = null): CheckResult
    {
        return new CheckResult($key, __('system.checks.'.$key), $status, $value, $hint);
    }
}
