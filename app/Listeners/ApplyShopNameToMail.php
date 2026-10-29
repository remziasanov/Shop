<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\ShopName;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Mail sent from the default address (MAIL_FROM_ADDRESS) carries the shop name from the settings as the sender
 * name, so renaming the shop in the admin panel needs no .env change. Other senders are left as they are.
 */
final class ApplyShopNameToMail
{
    public function handle(MessageSending $event): void
    {
        $default = (string) config('mail.from.address');
        $from = $event->message->getFrom();

        if ($default === '' || count($from) !== 1 || strcasecmp($from[0]->getAddress(), $default) !== 0) {
            return;
        }

        $event->message->from(new Address($from[0]->getAddress(), ShopName::get()));
    }
}
