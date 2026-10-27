<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Escaping for Telegram `parse_mode=HTML`: every interpolated value goes through here (NT-02).
 */
final class HtmlEscaper
{
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
