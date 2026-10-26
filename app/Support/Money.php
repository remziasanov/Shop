<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money helper (D-006, D-009): amounts are integer kopecks in code, never floats.
 */
final class Money
{
    /** Narrow no-break space, used as the thousands separator. */
    public const THOUSANDS_SEPARATOR = "\u{202F}";

    /** No-break space between the amount and the currency sign. */
    public const CURRENCY_SEPARATOR = "\u{00A0}";

    public const CURRENCY_SIGN = '₽';

    /**
     * Parse a rubles amount ("1 290,50", "1290.5", "1 290 ₽", 12, 12.5) into kopecks.
     *
     * More than two decimal digits are rounded half-up. Empty input is treated as zero.
     *
     * @throws InvalidArgumentException when the input is not a valid amount
     */
    public static function fromRubles(string|int|float|null $input): int
    {
        if ($input === null) {
            return 0;
        }

        $value = is_float($input) ? sprintf('%.4F', $input) : (string) $input;

        // Drop currency sign/word, regular, no-break and narrow no-break spaces.
        $value = preg_replace('/(₽|руб\.?|р\.?)|[\s\x{00A0}\x{202F}\x{2009}]+/u', '', $value) ?? '';
        $value = str_replace(',', '.', $value);

        if ($value === '') {
            return 0;
        }

        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid money amount: \"{$input}\".");
        }

        $negative = $m[1] === '-';
        $unsigned = $m[2].'.'.($m[3] ?? '0');

        // Half-up rounding to 2 decimals using bcmath on the absolute value.
        $rounded = bcadd($unsigned, '0.005', 2);
        $kopecks = (int) bcmul($rounded, '100', 0);

        return $negative ? -$kopecks : $kopecks;
    }

    /**
     * Kopecks to a plain rubles string with two decimals: 129050 => "1290.50".
     */
    public static function toRubles(int $kopecks): string
    {
        $negative = $kopecks < 0;
        $abs = abs($kopecks);

        $string = intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$string : $string;
    }

    /**
     * Human readable price: 129000 => "1 290 ₽", 129050 => "1 290,50 ₽".
     */
    public static function format(int $kopecks): string
    {
        $negative = $kopecks < 0;
        $abs = abs($kopecks);

        $rubles = number_format(intdiv($abs, 100), 0, '', self::THOUSANDS_SEPARATOR);
        $fraction = $abs % 100;

        $text = $rubles;

        if ($fraction !== 0) {
            $text .= ','.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
        }

        return ($negative ? '-' : '').$text.self::CURRENCY_SEPARATOR.self::CURRENCY_SIGN;
    }
}
