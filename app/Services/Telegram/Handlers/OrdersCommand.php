<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\Order;
use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramMessages;
use App\Settings\TelegramSettings;

/**
 * `/orders` (TG-05): the latest requests of this Telegram and a «Мои заказы» button; staff also get «Рабочее место»
 * (TS-05, extended by stage 18).
 */
class OrdersCommand
{
    private const LIMIT = 5;

    public function __construct(
        private readonly TelegramMessages $messages,
        private readonly TelegramSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(TelegramAccount $account): array
    {
        $orders = Order::query()
            ->where(function ($query) use ($account): void {
                $query->where('telegram_account_id', $account->id);

                if ($account->customer_id !== null) {
                    $query->orWhere('customer_id', $account->customer_id);
                }
            })
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        $text = $orders->isEmpty() ? __('telegram.bot.orders_empty') : __('telegram.bot.orders_title')."\n\n".$this->messages->orderLines($orders);
        $buttons = [$this->messages->shopButton(__('telegram.bot.my_orders'), 'orders')];

        if ($account->user_id !== null && $this->settings->staff_workplace_enabled && $this->settings->miniapp_enabled) {
            $buttons[] = $this->messages->shopButton(__('telegram.bot.workplace'), 'staff');
        }

        return $this->messages->reply($account, $text, ['inline_keyboard' => array_map(fn (array $button): array => [$button], $buttons)]);
    }
}
