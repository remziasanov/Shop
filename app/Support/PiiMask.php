<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masks personal data for logs and error reports (NF-15): phone `+7******4567`, email `i***@example.ru`.
 */
final class PiiMask
{
    public static function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        $plus = str_starts_with(trim((string) $phone), '+') ? '+' : '';

        if (strlen($digits) <= 4) {
            return $plus.str_repeat('*', strlen($digits));
        }

        $prefix = $plus !== '' ? substr($digits, 0, 1) : '';

        return $plus.$prefix.str_repeat('*', max(strlen($digits) - 4 - strlen($prefix), 0)).substr($digits, -4);
    }

    public static function email(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return '';
        }

        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return mb_substr($email, 0, 1).'***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
