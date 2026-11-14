<?php

declare(strict_types=1);

namespace App\Support;

/**
 * File size for people: 1536 gives «1,5 КБ». Units from `lang/ru/customer_export.php`.
 */
final class FileSize
{
    public static function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' '.__('customer_export.units.b');
        }

        if ($bytes < 1024 * 1024) {
            return self::number($bytes / 1024).' '.__('customer_export.units.kb');
        }

        return self::number($bytes / (1024 * 1024)).' '.__('customer_export.units.mb');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
