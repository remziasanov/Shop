<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a one-time Telegram deep link does (TG-07): `c_` links a customer, `s_` a staff user, `o_` follows guest requests.
 */
enum TelegramLinkPurpose: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Order = 'order';

    public function label(): string
    {
        return __('telegram.link_purpose.'.$this->value);
    }

    /** The prefix of the `start` parameter (TG-07). */
    public function prefix(): string
    {
        return match ($this) {
            self::Customer => 'c_',
            self::Staff => 's_',
            self::Order => 'o_',
        };
    }
}
