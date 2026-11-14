<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\WholesaleApplicationStatus;
use App\Models\Export;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\WholesaleApplication;
use App\Services\Notifications\HtmlEscaper;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use App\Support\Money;

/**
 * Texts and buttons of the customer Telegram messages (TN-03, NF-62, NF-64): `parse_mode=HTML`, every interpolated value
 * goes through {@see HtmlEscaper}, the texts are templates of `lang/ru/telegram_notify.php`. Only data of the recipient
 * is used (the request itself, the shop phone). Every message carries the «Не присылать уведомления» button (TN-06).
 */
class CustomerTelegramMessageBuilder
{
    /** Callback data of the «Не присылать уведомления» button. */
    public const STOP_CALLBACK = 'tn:stop';

    /** Item lines shown in a message; the rest is «…и ещё N поз.». */
    private const MAX_ITEM_LINES = 15;

    /** Longest item name in characters. */
    private const NAME_LIMIT = 100;

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramBotSetup $setup,
    ) {}

    public function created(Order $order): string
    {
        $order->loadMissing('items');

        $lines = [
            '<b>'.$this->e(__('telegram_notify.created.title', ['number' => $order->number])).'</b>',
            $this->e(__('telegram_notify.type')).': '.$this->e($order->type->label()),
            '',
            ...$this->itemLines($order),
        ];

        $lines = [...$lines, ...$this->totalLines($order)];

        if (filled($order->delivery_method)) {
            $lines[] = $this->e(__('telegram_notify.delivery')).': '.$this->e($order->delivery_method);
        }

        if (filled($order->delivery_address)) {
            $lines[] = $this->e(__('telegram_notify.address')).': '.$this->e($order->delivery_address);
        }

        if ($order->type === OrderType::Reservation && $order->reserved_until !== null) {
            $lines[] = $this->e(__('telegram_notify.reserved_until')).': '.$this->e($order->reserved_until->format('d.m.Y H:i'));
        }

        return $this->join($lines);
    }

    public function status(Order $order, OrderStatus $to): string
    {
        $lines = [
            '<b>'.$this->e(__('telegram_notify.status.title', ['type' => $order->type->label(), 'number' => $order->number])).'</b>',
            $this->e(__('telegram_notify.status.status')).': '.$this->e($to->label($order->type)),
        ];

        if ($order->type === OrderType::Reservation && $to === OrderStatus::Reserved && $order->reserved_until !== null) {
            $lines[] = $this->e(__('telegram_notify.reserved_until')).': '.$this->e($order->reserved_until->format('d.m.Y H:i'));
        }

        return $this->join($lines);
    }

    /** The reservation ended without a purchase: its term passed (`expired`) or it was cancelled (`cancelled`). */
    public function reservationEnded(Order $order, OrderStatus $to): string
    {
        $key = $to === OrderStatus::Expired ? 'expired' : 'released';

        return $this->join([
            '<b>'.$this->e(__('telegram_notify.'.$key.'.title', ['number' => $order->number])).'</b>',
            $this->e(__('telegram_notify.'.$key.'.text')),
        ]);
    }

    public function expiring(Order $order): string
    {
        $lines = [
            '<b>'.$this->e(__('telegram_notify.expiring.title', ['number' => $order->number])).'</b>',
            $this->e(__('telegram_notify.reserved_until')).': '.$this->e($order->reserved_until?->format('d.m.Y H:i') ?? '—'),
            $this->e(__('telegram_notify.expiring.text')),
        ];

        $phone = $this->shopPhone();

        if ($phone !== null) {
            $lines[] = $this->e(__('telegram_notify.expiring.phone', ['phone' => $phone]));
        }

        return $this->join($lines);
    }

    public function itemsChanged(Order $order, int $oldTotal, int $newTotal): string
    {
        $order->loadMissing('items');

        $lines = [
            '<b>'.$this->e(__('telegram_notify.items_changed.title', ['number' => $order->number])).'</b>',
            '',
            ...$this->itemLines($order),
        ];

        $lines[] = $oldTotal === $newTotal
            ? $this->e(__('telegram_notify.total')).': <b>'.$this->e(Money::format($newTotal)).'</b>'
            : $this->e(__('telegram_notify.items_changed.total', ['old' => Money::format($oldTotal), 'new' => Money::format($newTotal)]));

        return $this->join($lines);
    }

    public function wholesale(WholesaleApplication $application, ?string $groupName): string
    {
        if ($application->status === WholesaleApplicationStatus::Approved) {
            return $this->join([
                '<b>'.$this->e(__('telegram_notify.wholesale.approved_title')).'</b>',
                $groupName !== null ? $this->e(__('telegram_notify.wholesale.approved_text', ['group' => $groupName])) : '',
            ]);
        }

        return $this->join([
            '<b>'.$this->e(__('telegram_notify.wholesale.rejected_title')).'</b>',
            filled($application->reject_reason) ? $this->e(__('telegram_notify.wholesale.reason')).': '.$this->e($application->reject_reason) : '',
        ]);
    }

    public function exportReady(Export $export, string $format): string
    {
        return $this->join([
            '<b>'.$this->e(__('telegram_notify.export.title')).'</b>',
            $this->e(__('telegram_notify.export.text', [
                'format' => $format,
                'until' => ($export->expires_at ?? now()->addDay())->format('d.m.Y H:i'),
            ])),
        ]);
    }

    /**
     * The inline keyboard: «Открыть заказ» (and «Позвонить в магазин» for an expiring reservation), then «Не присылать
     * уведомления». Without an order the first button opens the shop.
     *
     * @return array<string, mixed>
     */
    public function markup(?Order $order, bool $withCall = false): array
    {
        $first = [$order !== null ? $this->openOrderButton($order) : $this->openShopButton()];

        if ($withCall) {
            $first[] = ['text' => __('telegram_notify.button.call'), 'url' => $this->contactsUrl()];
        }

        return ['inline_keyboard' => [$first, [['text' => __('telegram_notify.button.stop'), 'callback_data' => self::STOP_CALLBACK]]]];
    }

    /**
     * `web_app` to `{APP_URL}/tg?to=o_{uuid}` (TN-03); the website page of the request while the Mini App is off.
     *
     * @return array<string, mixed>
     */
    private function openOrderButton(Order $order): array
    {
        $text = __('telegram_notify.button.open_order');

        if ($this->settings->miniapp_enabled) {
            return ['text' => $text, 'web_app' => ['url' => $this->setup->miniAppUrl().'?to=o_'.$order->uuid]];
        }

        return ['text' => $text, 'url' => $this->setup->baseUrl().route('order.thanks', $order->uuid, false)];
    }

    /**
     * @return array<string, mixed>
     */
    private function openShopButton(): array
    {
        $text = __('telegram_notify.button.open_shop');

        return $this->settings->miniapp_enabled
            ? ['text' => $text, 'web_app' => ['url' => $this->setup->miniAppUrl()]]
            : ['text' => $text, 'url' => $this->setup->baseUrl().'/'];
    }

    /**
     * `tel:` is not allowed in Telegram buttons, so the button leads to the contacts page of the shop (home page when
     * there is none).
     */
    private function contactsUrl(): string
    {
        $page = Page::query()->active()->where('slug', 'kontakty')->first();

        return $this->setup->baseUrl().($page !== null ? route('page.show', $page->slug, false) : '/');
    }

    private function shopPhone(): ?string
    {
        $phones = array_values(array_filter((array) (app(GeneralSettings::class)->phones ?? [])));

        return $phones === [] ? null : (string) $phones[0];
    }

    /**
     * @return list<string>
     */
    private function itemLines(Order $order): array
    {
        if ($order->items->isEmpty()) {
            return [];
        }

        $lines = [$this->e(__('telegram_notify.items')).':'];

        foreach ($order->items->take(self::MAX_ITEM_LINES) as $item) {
            /** @var OrderItem $item */
            $sum = $item->total === null ? __('telegram_notify.price_on_request') : Money::format(Money::fromRubles($item->total));
            $lines[] = '• '.$this->e(mb_strimwidth($item->displayName(), 0, self::NAME_LIMIT, '…')).' × '.$this->e($item->qty).' — '.$this->e($sum);
        }

        if ($order->items->count() > self::MAX_ITEM_LINES) {
            $lines[] = $this->e(__('telegram_notify.more_items', ['count' => $order->items->count() - self::MAX_ITEM_LINES]));
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function totalLines(Order $order): array
    {
        if ($order->total === null) {
            return [];
        }

        return [$this->e(__('telegram_notify.total')).': <b>'.$this->e(Money::format(Money::fromRubles($order->total))).'</b>'];
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines): string
    {
        // A trailing or doubled blank line is noise; empty strings from optional parts are dropped at the ends.
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
    }

    private function e(string|int|float|null $value): string
    {
        return HtmlEscaper::e($value);
    }
}
