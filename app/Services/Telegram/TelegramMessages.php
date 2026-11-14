<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\TelegramAccount;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use Illuminate\Support\Collection;

/**
 * Builds the bot's replies as Bot API method arrays (they go into the webhook response body, TG-05) and the
 * «Открыть магазин» buttons. Texts are plain (no parse mode), so no escaping is needed.
 */
class TelegramMessages
{
    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramBotSetup $setup,
    ) {}

    /**
     * A `sendMessage` reply to the private chat of `$account`.
     *
     * @param  array<string, mixed>|null  $markup
     * @return array<string, mixed>
     */
    public function reply(TelegramAccount $account, string $text, ?array $markup = null): array
    {
        return $this->toChat((string) $account->telegram_user_id, $text, $markup);
    }

    /**
     * @param  array<string, mixed>|null  $markup
     * @return array<string, mixed>
     */
    public function toChat(string $chatId, string $text, ?array $markup = null): array
    {
        return array_filter([
            'method' => 'sendMessage',
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $markup,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The «Открыть магазин» inline keyboard: the Mini App when it is on, else the website.
     *
     * @return array<string, mixed>
     */
    public function openShopMarkup(?string $path = null): array
    {
        return ['inline_keyboard' => [[$this->shopButton(__('telegram.bot.open_shop'), $path)]]];
    }

    /**
     * @return array<string, mixed>
     */
    public function shopButton(string $text, ?string $startParam = null): array
    {
        if ($this->settings->miniapp_enabled) {
            return ['text' => $text, 'web_app' => ['url' => $this->setup->miniAppUrl().($startParam !== null ? '?start_param='.$startParam : '')]];
        }

        return ['text' => $text, 'url' => $this->setup->baseUrl().'/'];
    }

    public function welcomeText(): string
    {
        $custom = $this->settings->welcome_text;

        return filled($custom) ? (string) $custom : __('telegram.bot.welcome', ['shop' => app(GeneralSettings::class)->shop_name]);
    }

    public function helpText(): string
    {
        $custom = $this->settings->help_text;

        return filled($custom) ? (string) $custom : __('telegram.bot.help');
    }

    /** «Откройте магазин или позвоните {телефон}» (TG-05). */
    public function autoReplyText(): string
    {
        $phones = array_values(array_filter((array) (app(GeneralSettings::class)->phones ?? [])));

        return $phones === []
            ? __('telegram.bot.autoreply_no_phone')
            : __('telegram.bot.autoreply', ['phone' => (string) $phones[0]]);
    }

    /**
     * «№ 123 — Новый» per line.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function orderLines(Collection $orders): string
    {
        return $orders
            ->map(fn (Order $order): string => $order->type->label().' '.$order->number.' — '.$order->status->label($order->type))
            ->implode("\n");
    }
}
