<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a request came from (TM-14): the site in a browser, the installed app (PWA, MB-23) or the Telegram Mini App.
 * `telegram` is set by the server only, a client can never send it (see OrderChannelResolver).
 */
enum OrderChannel: string
{
    case Web = 'web';
    case Pwa = 'pwa';
    case Telegram = 'telegram';

    public function label(): string
    {
        return __('pwa.channel.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Web => 'gray',
            self::Pwa => 'info',
            self::Telegram => 'success',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
