<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramAccount;
use App\Services\Notifications\HtmlEscaper;
use App\Services\Notifications\TelegramException;
use App\Services\Notifications\TelegramNotifier;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * «Подключить бота» (TG-02): token check, webhook, commands and menu button; the state block; the Main Mini App
 * link check (TG-03); token change (TG-11). Every Bot API step returns a result row for the settings page.
 */
class TelegramBotSetup
{
    /** Bot commands in the order of the menu (texts: lang `telegram.commands.*`). */
    public const COMMANDS = ['start', 'orders', 'help', 'stop'];

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramClient $client,
        private readonly TelegramNotifier $notifier,
    ) {}

    /** Telegram only accepts an https webhook and Mini App address. */
    public function canConnect(): bool
    {
        return str_starts_with(strtolower(trim((string) config('app.url'))), 'https://');
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) config('app.url')), '/');
    }

    public function webhookUrl(): string
    {
        return $this->baseUrl().'/telegram/webhook';
    }

    public function miniAppUrl(): string
    {
        return $this->baseUrl().'/tg';
    }

    /** The bot is connected: set up by «Подключить бота» and not disconnected since. */
    public function isConnected(): bool
    {
        return $this->settings->connected_at !== null && filled($this->settings->bot_token) && filled($this->settings->bot_username);
    }

    /** `https://t.me/{bot}?start={param}`; null while the bot is not connected. */
    public function startLink(string $param): ?string
    {
        return $this->isConnected() ? 'https://t.me/'.$this->settings->bot_username.'?start='.$param : null;
    }

    /** TG-03: `https://t.me/{bot}?startapp=check`. */
    public function startappCheckUrl(): ?string
    {
        return filled($this->settings->bot_username) ? 'https://t.me/'.$this->settings->bot_username.'?startapp=check' : null;
    }

    public function startappVerified(): bool
    {
        return $this->settings->startapp_verified_at !== null;
    }

    /**
     * TG-03: called by the Mini App entry when it sees `start_param=check`.
     */
    public function markStartappVerified(): void
    {
        $this->settings->startapp_verified_at = CarbonImmutable::now()->toIso8601String();
        $this->settings->save();
    }

    /**
     * Runs the connect sequence: getMe → setWebhook → setMyCommands → setChatMenuButton. Stops at the first failed
     * step. With `$newToken` the token is checked first and saved only if getMe accepts it (TG-11).
     *
     * @return list<array{key: string, ok: bool, detail: string}>
     */
    public function connect(?string $newToken = null): array
    {
        if (! $this->canConnect()) {
            return [['key' => 'https', 'ok' => false, 'detail' => __('telegram.admin.https_required')]];
        }

        $token = filled($newToken) ? trim((string) $newToken) : null;
        $steps = [];

        try {
            $me = $this->client->call('getMe', [], $token);
        } catch (Throwable $e) {
            return [['key' => 'getMe', 'ok' => false, 'detail' => $e->getMessage()]];
        }

        $botId = isset($me['id']) ? (int) $me['id'] : null;
        $username = isset($me['username']) ? (string) $me['username'] : null;
        $previousBotId = $this->settings->bot_id;
        $botChanged = $previousBotId !== null && $botId !== null && $previousBotId !== $botId;

        if ($token !== null) {
            $this->settings->bot_token = $token;
        }

        $this->settings->bot_id = $botId;
        $this->settings->bot_username = $username;
        $this->settings->save();
        $steps[] = ['key' => 'getMe', 'ok' => true, 'detail' => '@'.$username];

        // TG-11: another bot cannot write to people until they press /start in it.
        if ($botChanged) {
            TelegramAccount::query()->update(['chat_started_at' => null]);
            $steps[] = ['key' => 'bot_changed', 'ok' => true, 'detail' => __('telegram.admin.bot_changed')];
        }

        // The secret is rotated on every connect and stored only after Telegram has accepted it.
        $secret = bin2hex(random_bytes(32));

        $calls = [
            'setWebhook' => ['setWebhook', [
                'url' => $this->webhookUrl(),
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
                'max_connections' => 5,
            ]],
            'setMyCommands' => ['setMyCommands', ['commands' => $this->commands()]],
            'setChatMenuButton' => ['setChatMenuButton', ['menu_button' => [
                'type' => 'web_app',
                'text' => $this->settings->menu_button_text !== '' ? $this->settings->menu_button_text : __('telegram.admin.menu_button_default'),
                'web_app' => ['url' => $this->miniAppUrl()],
            ]]],
        ];

        foreach ($calls as $key => [$method, $params]) {
            try {
                $this->client->call($method, $params);
            } catch (Throwable $e) {
                $steps[] = ['key' => $key, 'ok' => false, 'detail' => $e->getMessage()];

                return $steps;
            }

            if ($key === 'setWebhook') {
                $this->settings->webhook_secret = $secret;
                $this->settings->save();
            }

            $steps[] = ['key' => $key, 'ok' => true, 'detail' => $key === 'setWebhook' ? $this->webhookUrl() : ''];
        }

        $this->settings->connected_at = CarbonImmutable::now()->toIso8601String();
        $this->settings->save();
        Cache::forget(TelegramDoctor::CACHE_KEY);

        return $steps;
    }

    /**
     * @return list<array{command: string, description: string}>
     */
    public function commands(): array
    {
        return array_map(fn (string $command): array => [
            'command' => $command,
            'description' => __('telegram.commands.'.$command),
        ], self::COMMANDS);
    }

    /**
     * «Состояние»: getWebhookInfo.
     *
     * @return array{url: string, pending: int, last_error: ?string, last_error_at: ?CarbonImmutable}
     *
     * @throws TelegramApiException
     */
    public function webhookState(): array
    {
        $info = $this->client->call('getWebhookInfo');
        $errorDate = $info['last_error_date'] ?? null;

        return [
            'url' => (string) ($info['url'] ?? ''),
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => isset($info['last_error_message']) ? (string) $info['last_error_message'] : null,
            'last_error_at' => is_numeric($errorDate) ? CarbonImmutable::createFromTimestamp((int) $errorDate) : null,
        ];
    }

    /**
     * «Отключить»: deleteWebhook and the default menu button; token, links and accounts stay.
     *
     * @return list<array{key: string, ok: bool, detail: string}>
     */
    public function disconnect(): array
    {
        $steps = [];

        foreach ([
            'deleteWebhook' => ['deleteWebhook', ['drop_pending_updates' => false]],
            'setChatMenuButton' => ['setChatMenuButton', ['menu_button' => ['type' => 'default']]],
        ] as $key => [$method, $params]) {
            try {
                $this->client->call($method, $params);
                $steps[] = ['key' => $key, 'ok' => true, 'detail' => ''];
            } catch (Throwable $e) {
                $steps[] = ['key' => $key, 'ok' => false, 'detail' => $e->getMessage()];
            }
        }

        $this->settings->connected_at = null;
        $this->settings->webhook_secret = null;
        $this->settings->save();
        Cache::forget(TelegramDoctor::CACHE_KEY);

        return $steps;
    }

    /**
     * «Отправить тест в рабочий чат» (NT-04).
     *
     * @throws TelegramApiException|TelegramException
     */
    public function sendWorkChatTest(): void
    {
        $this->notifier->send(HtmlEscaper::e(__('notifications.telegram.test', ['shop' => app(GeneralSettings::class)->shop_name])));
    }
}
