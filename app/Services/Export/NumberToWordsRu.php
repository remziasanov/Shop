<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * An amount in words, in Russian, for the «итого прописью» line of the PDF document (CX-11): 123005 kopecks give
 * «Одна тысяча двести тридцать рублей 05 копеек». Works on integer kopecks only, no floats.
 */
final class NumberToWordsRu
{
    private const ONES_MASCULINE = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];

    private const ONES_FEMININE = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];

    private const TEENS = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];

    private const TENS = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];

    private const HUNDREDS = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

    /** Scales above hundreds: [one, few, many forms, feminine]. */
    private const SCALES = [
        1 => ['тысяча', 'тысячи', 'тысяч', true],
        2 => ['миллион', 'миллиона', 'миллионов', false],
        3 => ['миллиард', 'миллиарда', 'миллиардов', false],
        4 => ['триллион', 'триллиона', 'триллионов', false],
    ];

    /**
     * «Сто рублей 00 копеек»; a negative amount is written without a sign (the caller prints the sign).
     */
    public function rubles(int $kopecks): string
    {
        $kopecks = abs($kopecks);
        $rubles = intdiv($kopecks, 100);
        $fraction = $kopecks % 100;

        $words = $this->integer($rubles, false);
        $words = mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);

        return $words.' '.self::plural($rubles, 'рубль', 'рубля', 'рублей')
            .' '.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT).' '.self::plural($fraction, 'копейка', 'копейки', 'копеек');
    }

    /**
     * A non-negative integer in words, lowercase: 0 gives «ноль».
     */
    public function integer(int $number, bool $feminine = false): string
    {
        if ($number === 0) {
            return 'ноль';
        }

        $groups = [];

        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];

        foreach (array_reverse($groups, true) as $scale => $group) {
            if ($group === 0) {
                continue;
            }

            [$one, $few, $many, $groupFeminine] = self::SCALES[$scale] ?? ['', '', '', $feminine];

            $parts[] = $this->triplet($group, $scale === 0 ? $feminine : $groupFeminine);

            if ($scale > 0) {
                $parts[] = self::plural($group, $one, $few, $many);
            }
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    private function triplet(int $number, bool $feminine): string
    {
        $words = [self::HUNDREDS[intdiv($number, 100)]];
        $rest = $number % 100;

        if ($rest >= 10 && $rest < 20) {
            $words[] = self::TEENS[$rest - 10];
        } else {
            $words[] = self::TENS[intdiv($rest, 10)];
            $words[] = ($feminine ? self::ONES_FEMININE : self::ONES_MASCULINE)[$rest % 10];
        }

        return implode(' ', array_filter($words, fn (string $word): bool => $word !== ''));
    }

    /**
     * The noun form after a number: 1 рубль, 2 рубля, 5 рублей, 11 рублей, 21 рубль.
     */
    public static function plural(int $number, string $one, string $few, string $many): string
    {
        $mod100 = $number % 100;
        $mod10 = $number % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match (true) {
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }
}
