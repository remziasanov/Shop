<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\SalesMode;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Parsing of single cell values (IM-07, appendix A). Every method treats a blank cell as "no value" (null)
 * and throws {@see ValueParseException} with a translated message for a malformed value.
 */
final class ValueParser
{
    /** Largest amount of decimal(12,2) in kopecks. */
    public const MAX_KOPECKS = 999_999_999_999;

    /** Largest value of an `integer` column. */
    public const MAX_INT = 2_147_483_647;

    /** Marker of "use the default sales mode" (explicit, unlike a blank cell). */
    public const SALES_MODE_DEFAULT = 'default';

    private const TRUE_WORDS = ['да', 'д', 'yes', 'y', 'true', '1', '+', 'истина', 'вкл', 'on'];

    private const FALSE_WORDS = ['нет', 'н', 'no', 'n', 'false', '0', '-', '−', '–', '—', 'ложь', 'выкл', 'off'];

    /**
     * Rubles text into kopecks: "1 290,50", "1290.5", "1 290 ₽", "12 990".
     *
     * @throws ValueParseException
     */
    public function price(?string $value): ?int
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        try {
            $kopecks = Money::fromRubles($value);
        } catch (InvalidArgumentException) {
            throw new ValueParseException(__('import.errors.invalid_price'));
        }

        if ($kopecks < 0) {
            throw new ValueParseException(__('import.errors.invalid_price'));
        }

        if ($kopecks > self::MAX_KOPECKS) {
            throw new ValueParseException(__('import.errors.price_too_big'));
        }

        return $kopecks;
    }

    /**
     * Whole number >= 0 ("15", "15.0", "1 500"); "1,5" is an error.
     *
     * @throws ValueParseException
     */
    public function stock(?string $value): ?int
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        $compact = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', $value) ?? '';

        if (preg_match('/^(\d+)(?:[.,]0+)?$/', $compact, $m) !== 1 || strlen($m[1]) > 10 || (int) $m[1] > self::MAX_INT) {
            throw new ValueParseException(__('import.errors.invalid_stock'));
        }

        return (int) $m[1];
    }

    /**
     * Signed whole number (sort order).
     *
     * @throws ValueParseException
     */
    public function integer(?string $value): ?int
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        $compact = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', $value) ?? '';
        $compact = str_replace('−', '-', $compact);

        if (preg_match('/^(-?\d+)(?:[.,]0+)?$/', $compact, $m) !== 1 || strlen(ltrim($m[1], '-')) > 10 || abs((int) $m[1]) > self::MAX_INT) {
            throw new ValueParseException(__('import.errors.invalid_integer'));
        }

        return (int) $m[1];
    }

    /**
     * да/нет/1/0/true/false/+/−/-/yes/no, case-insensitive.
     *
     * @throws ValueParseException
     */
    public function bool(?string $value): ?bool
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        $word = $this->word($value);

        if (in_array($word, self::TRUE_WORDS, true)) {
            return true;
        }

        if (in_array($word, self::FALSE_WORDS, true)) {
            return false;
        }

        throw new ValueParseException(__('import.errors.invalid_bool'));
    }

    /**
     * Sales mode word. Returns null for a blank cell; the explicit "по умолчанию" gives
     * {@see SALES_MODE_DEFAULT} (set the product to inherit the category/global mode).
     *
     * @return SalesMode|self::SALES_MODE_DEFAULT|null
     *
     * @throws ValueParseException
     */
    public function salesMode(?string $value): SalesMode|string|null
    {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        $word = $this->word($value);

        $map = [
            'корзина' => SalesMode::Cart,
            'cart' => SalesMode::Cart,
            'резерв' => SalesMode::Reservation,
            'reservation' => SalesMode::Reservation,
            'корзина и резерв' => SalesMode::Both,
            'корзина+резерв' => SalesMode::Both,
            'both' => SalesMode::Both,
            'заявка' => SalesMode::Inquiry,
            'inquiry' => SalesMode::Inquiry,
            'витрина' => SalesMode::Showcase,
            'showcase' => SalesMode::Showcase,
        ];

        if (isset($map[$word])) {
            return $map[$word];
        }

        if (in_array($word, ['по умолчанию', 'умолчанию', 'default', 'inherit', 'наследовать'], true)) {
            return self::SALES_MODE_DEFAULT;
        }

        throw new ValueParseException(__('import.errors.invalid_sales_mode'));
    }

    /**
     * File names / URLs separated by ";", "," or line breaks; trimmed, de-duplicated, order kept.
     *
     * @return list<string>
     */
    public function photos(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $parts = preg_split('/[;,\r\n]+/u', $value) ?: [];
        $result = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part !== '' && ! in_array($part, $result, true)) {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * "Электроника / Телефоны" => ['Электроника', 'Телефоны'] for the chosen separator.
     *
     * @return list<string>
     */
    public function categoryPath(?string $value, string $separator = '/'): array
    {
        if ($value === null || $separator === '') {
            return [];
        }

        $result = [];

        foreach (explode($separator, $value) as $part) {
            $part = trim((string) preg_replace('/\s+/u', ' ', $part));

            if ($part !== '') {
                $result[] = $part;
            }
        }

        return $result;
    }

    /**
     * Trim (incl. no-break spaces); blank => null.
     */
    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/^[\s\x{00A0}\x{202F}\x{2009}]+|[\s\x{00A0}\x{202F}\x{2009}]+$/u', '', $value));

        return $value === '' ? null : $value;
    }

    private function word(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace('ё', 'е', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
