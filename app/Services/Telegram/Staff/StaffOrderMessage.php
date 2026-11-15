<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use App\Enums\StaffOrderAction;
use App\Models\Order;
use App\Services\Notifications\Messages\NewRequestMessage;
use App\Services\Notifications\Messages\OrderMessage;
use App\Services\Telegram\MiniApp\MiniAppConfig;

/**
 * The staff message about a request, as sent to the work chat and to personal chats and as redrawn after a tap
 * (TS-01, TS-03): the text of {@see NewRequestMessage} and the inline keyboard of the actions that fit the status.
 */
final class StaffOrderMessage
{
    /** `callback_data` limit of the Bot API, in bytes. */
    public const CALLBACK_LIMIT = 64;

    public function __construct(
        private readonly StaffOrderActions $actions,
        private readonly StaffAccess $access,
        private readonly MiniAppConfig $config,
    ) {}

    public function text(Order $order): string
    {
        return (new NewRequestMessage)->render($order);
    }

    /**
     * `o:{action}:{order id}:{version}`
     */
    public static function callbackData(StaffOrderAction $action, Order $order): string
    {
        return 'o:'.$action->value.':'.$order->id.':'.$order->version;
    }

    /**
     * The inline keyboard: the action buttons by status, then «Открыть в админке» and «Рабочее место». Null while the
     * buttons are switched off in the settings (TG-01): the message is then the plain v2.0 one.
     *
     * @param  bool  $cancelArmed  the first tap of «Отменить» happened: the button asks «Точно отменить?» (TS-04)
     * @return array{inline_keyboard: list<list<array<string, mixed>>>}|null
     */
    public function markup(Order $order, bool $cancelArmed = false): ?array
    {
        if (! $this->access->buttonsEnabled()) {
            return null;
        }

        $rows = [];
        $row = [];

        foreach ($this->actions->available($order) as $action) {
            $label = $action === StaffOrderAction::Cancel && $cancelArmed ? __('telegram_staff.action.cancel_sure') : $action->label($order->type);
            $row[] = ['text' => $label, 'callback_data' => self::callbackData($action, $order)];
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        $links = [['text' => __('telegram_staff.action.open_admin'), 'url' => OrderMessage::adminUrl($order)]];
        $workplace = $this->workplaceUrl($order);

        if ($workplace !== null) {
            $links[] = ['text' => __('telegram_staff.action.workplace'), 'url' => $workplace];
        }

        $rows[] = $links;

        return ['inline_keyboard' => $rows];
    }

    /**
     * `t.me/{bot}?startapp=staff_o_{id}` once the startapp links are verified (TG-03); until then `t.me/{bot}?start=staff`,
     * which the bot answers in the private chat with a `web_app` button (web_app buttons do not work in groups). Null
     * when the workplace is off or the bot is unknown.
     */
    public function workplaceUrl(Order $order): ?string
    {
        if (! $this->access->workplaceEnabled()) {
            return null;
        }

        $base = $this->config->botUrl();

        if ($base === null) {
            return null;
        }

        return $this->config->startappVerified()
            ? $base.'?startapp=staff_o_'.$order->id
            : $base.'?start=staff';
    }
}
