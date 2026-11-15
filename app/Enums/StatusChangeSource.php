<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a status change or an audited action came from (TS-03, TS-07): the admin panel, Telegram, the customer or the system. The audit log only uses `admin` and `telegram`.
 */
enum StatusChangeSource: string
{
    case Admin = 'admin';
    case Telegram = 'telegram';
    case Customer = 'customer';
    case System = 'system';

    public function label(): string
    {
        return __('telegram.change_source.'.$this->value);
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
