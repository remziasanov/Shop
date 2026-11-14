<?php

declare(strict_types=1);

use App\Services\Export\NumberToWordsRu;

/**
 * CX-11: the amount in words of the PDF document.
 */
it('writes rubles and kopecks in words', function (int $kopecks, string $expected): void {
    expect((new NumberToWordsRu)->rubles($kopecks))->toBe($expected);
})->with([
    'zero' => [0, 'Ноль рублей 00 копеек'],
    'one kopeck' => [1, 'Ноль рублей 01 копейка'],
    'one ruble' => [100, 'Один рубль 00 копеек'],
    'two rubles 02' => [202, 'Два рубля 02 копейки'],
    'five rubles 5 kopecks' => [505, 'Пять рублей 05 копеек'],
    'eleven' => [1100, 'Одиннадцать рублей 00 копеек'],
    'twenty-one' => [2100, 'Двадцать один рубль 00 копеек'],
    'one hundred' => [10000, 'Сто рублей 00 копеек'],
    'one thousand (feminine)' => [100000, 'Одна тысяча рублей 00 копеек'],
    'two thousand (feminine)' => [200000, 'Две тысячи рублей 00 копеек'],
    'twelve thousand' => [1200000, 'Двенадцать тысяч рублей 00 копеек'],
    'typical invoice' => [12345650, 'Сто двадцать три тысячи четыреста пятьдесят шесть рублей 50 копеек'],
    'million' => [100000000, 'Один миллион рублей 00 копеек'],
    'million with thousands' => [215000050 * 100 + 99, 'Двести пятнадцать миллионов пятьдесят рублей 99 копеек'],
    'two million and a thousand' => [200100000, 'Два миллиона одна тысяча рублей 00 копеек'],
    'billion' => [2_000_000_000_00, 'Два миллиарда рублей 00 копеек'],
    'kopecks 11 to 14' => [112, 'Один рубль 12 копеек'],
    'kopecks 21' => [121, 'Один рубль 21 копейка'],
]);

it('ignores the sign of a negative amount', function (): void {
    expect((new NumberToWordsRu)->rubles(-150))->toBe('Один рубль 50 копеек');
});

it('picks the plural form of a noun', function (): void {
    expect(NumberToWordsRu::plural(1, 'рубль', 'рубля', 'рублей'))->toBe('рубль')
        ->and(NumberToWordsRu::plural(3, 'рубль', 'рубля', 'рублей'))->toBe('рубля')
        ->and(NumberToWordsRu::plural(14, 'рубль', 'рубля', 'рублей'))->toBe('рублей')
        ->and(NumberToWordsRu::plural(101, 'рубль', 'рубля', 'рублей'))->toBe('рубль')
        ->and(NumberToWordsRu::plural(112, 'рубль', 'рубля', 'рублей'))->toBe('рублей');
});
