<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderChannel;
use App\Support\TelegramMode;

/**
 * Decides the channel of a new request from what the browser sent (MB-23, TM-14). The client may only say `pwa`; every
 * other value, including `telegram`, falls back to `web`. In the Telegram mode (a session flag) the channel is
 * `telegram` whatever the form sent: the installed app never runs inside Telegram.
 */
final class OrderChannelResolver
{
    public function resolve(mixed $fromClient): OrderChannel
    {
        if (TelegramMode::active()) {
            return OrderChannel::Telegram;
        }

        return $fromClient === OrderChannel::Pwa->value ? OrderChannel::Pwa : OrderChannel::Web;
    }
}
