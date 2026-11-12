<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Parsing of raw characteristic values (FL-03, FL-04, FL-16): lists split on «;», numbers with an optional unit
 * suffix ("1 200,5 кг"), booleans («да» / «нет»). Pure functions, no I/O.
 */
final class AttributeValueParser
{
    /** Longest suffix that is taken for a unit (FL-16 rule 3). */
    public const MAX_UNIT_LENGTH = 12;

    /** Largest absolute value of decimal(14,4): ten integer digits. */
    private const MAX_INTEGER_DIGITS = 10;

    private const NUMBER_PATTERN = '/^([+-]?(?:\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+))(?:[.,](\d+))?\s*(.*)$/us';

    /** @var list<string> */
    private const TRUE_WORDS = ['да', 'yes', 'true', '1'];

    /** @var list<string> */
    private const FALSE_WORDS = ['нет', 'no', 'false', '0'];

    /**
     * Cells of list characteristics hold several values separated by «;» (FL-16). Blank parts are dropped.
     *
     * @return list<string>
     */
    public static function splitList(string $raw): array
    {
        $parts = [];

        foreach (explode(';', $raw) as $part) {
            $part = self::collapse($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Trim and collapse whitespace (non-breaking spaces included).
     */
    public static function collapse(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    /**
     * Number with an optional unit suffix. Null when the text does not start with a number or the suffix is
     * not a plausible unit (starts with a digit or punctuation, longer than 12 characters).
     *
     * @return array{number: string, suffix: string}|null `number` is a plain decimal ("-1200.5"), not rounded
     */
    public static function parseNumber(string $raw): ?array
    {
        $raw = self::collapse($raw);

        if ($raw === '' || preg_match(self::NUMBER_PATTERN, $raw, $m) !== 1) {
            return null;
        }

        $suffix = trim($m[3]);

        if ($suffix !== '' && (mb_strlen($suffix) > self::MAX_UNIT_LENGTH || preg_match('/^[^\d\s.,;:+\-]/u', $suffix) !== 1)) {
            return null;
        }

        $integer = str_replace([' ', "\u{00A0}"], '', $m[1]);
        $fraction = $m[2];

        return ['number' => $integer.($fraction !== '' ? '.'.$fraction : ''), 'suffix' => $suffix];
    }

    /**
     * Plain decimal rounded half away from zero to the 4 decimals of decimal(14,4) ("2.5" => "2.5000");
     * null when it does not fit the column.
     */
    public static function toDecimal(string $number): ?string
    {
        if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $number) !== 1) {
            return null;
        }

        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '+');
        $rounded = bcadd($number, ($negative ? '-' : '').'0.00005', 4);

        if (preg_match('/^-0\.0+$/', $rounded) === 1) {
            $rounded = '0.0000';
        }

        $integerDigits = strlen(ltrim(explode('.', ltrim($rounded, '-'))[0], '0'));

        return $integerDigits > self::MAX_INTEGER_DIGITS ? null : $rounded;
    }

    /**
     * Database decimal for display without trailing zeros: "50.0000" => "50", "2.5000" => "2.5".
     */
    public static function formatNumber(string|int|float $decimal): string
    {
        $text = is_string($decimal) ? $decimal : number_format((float) $decimal, 4, '.', '');

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /**
     * «да» / «нет» / yes / no / true / false / 1 / 0 (any case, booleans and ints included); null otherwise.
     */
    public static function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return null;
        }

        $word = mb_strtolower(self::collapse($value), 'UTF-8');

        return match (true) {
            in_array($word, self::TRUE_WORDS, true) => true,
            in_array($word, self::FALSE_WORDS, true) => false,
            default => null,
        };
    }

    /**
     * The strict set of FL-04 for type inference: да / нет / yes / no / 1 / 0.
     */
    public static function isBooleanWord(string $value): bool
    {
        $word = mb_strtolower(self::collapse($value), 'UTF-8');

        return in_array($word, ['да', 'нет', 'yes', 'no', '1', '0'], true);
    }
}
