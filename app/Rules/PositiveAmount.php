<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A positive decimal typed by a person ("10", "12,5", "1 290.50") with a limited number of decimals.
 * Used for price rule values and the bulk price change (percent: 4 decimals, rubles: 2).
 */
class PositiveAmount implements ValidationRule
{
    /** Integer digits that fit the decimal(12,4) column. */
    public const MAX_INTEGER_DIGITS = 8;

    /**
     * @param  string|null  $max  inclusive upper bound as a plain decimal string
     */
    public function __construct(private readonly int $decimals = 2, private readonly ?string $max = null) {}

    /**
     * Canonical "1290.5" form (dot, no spaces) or null when the input is not a plain decimal number.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', (string) $value));

        return preg_match('/^\d+(\.\d+)?$/', $text) === 1 ? $text : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $number = self::normalize($value);

        if ($number === null || bccomp($number, '0', 6) <= 0) {
            $fail('admin_pricing.validation.positive')->translate();

            return;
        }

        [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, '');

        if (strlen(ltrim($integer, '0')) > self::MAX_INTEGER_DIGITS || strlen(rtrim($fraction, '0')) > $this->decimals) {
            $fail('admin_pricing.validation.decimals')->translate(['decimals' => $this->decimals]);

            return;
        }

        if ($this->max !== null && bccomp($number, $this->max, 6) > 0) {
            $fail('admin_pricing.validation.max')->translate(['max' => $this->max]);
        }
    }
}
