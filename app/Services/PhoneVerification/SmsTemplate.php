<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

/**
 * The SMS text of PV-04: placeholders {code} and {shop}, one Cyrillic SMS (70 characters), no links. The shop name is
 * shortened when the text would not fit.
 */
final class SmsTemplate
{
    public const MAX_LENGTH = 70;

    public const DEFAULT = 'Код подтверждения: {code}. {shop}';

    public static function render(string $template, string $code, string $shop): string
    {
        $withoutShop = str_replace('{code}', $code, $template);
        $count = substr_count($withoutShop, '{shop}');

        if ($count === 0) {
            return $withoutShop;
        }

        // PV-04: no links, a domain-like shop name («Alev.ru») loses its zone.
        $shop = trim(preg_replace(['~https?://\S*|www\.\S*~iu', '~\b([\p{L}\d-]+)\.(?:ru|com|net|org|su|рф|io|me|ly|by|kz|shop|store|online|site)\b~iu'], ['', '$1'], $shop) ?? $shop);

        $fixed = mb_strlen(str_replace('{shop}', '', $withoutShop));
        $room = max(0, intdiv(self::MAX_LENGTH - $fixed, $count));
        $shop = $room >= mb_strlen($shop) ? $shop : rtrim(mb_substr($shop, 0, $room), " \t.,;:-–—");

        return trim(str_replace('{shop}', $shop, $withoutShop));
    }

    /**
     * Error key (lang `phone_verification.template.*`) or null when the template is valid: it needs {code}, must fit one
     * SMS even with an empty shop name and must not hold links.
     */
    public static function validate(string $template): ?string
    {
        if (! str_contains($template, '{code}')) {
            return 'no_code';
        }

        if (preg_match('~https?://|www\.|\b[\p{L}\d-]+\.(ru|com|net|org|su|рф|io|me|ly|by|kz)\b~iu', $template) === 1) {
            return 'link';
        }

        if (mb_strlen(self::render($template, '0000', '')) > self::MAX_LENGTH) {
            return 'too_long';
        }

        return null;
    }
}
