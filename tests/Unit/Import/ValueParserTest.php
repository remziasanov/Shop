<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Services\Import\ValueParseException;
use App\Services\Import\ValueParser;

it('parses prices into kopecks', function (?string $input, ?int $expected) {
    expect((new ValueParser)->price($input))->toBe($expected);
})->with([
    'spaces and comma' => ['1 290,50', 129050],
    'dot' => ['1290.5', 129050],
    'currency' => ['1 290 ₽', 129000],
    'thousands' => ['12 990', 1299000],
    'nbsp' => ["12\u{00A0}990", 1299000],
    'thin space' => ["12\u{2009}990", 1299000],
    'narrow nbsp with sign' => ["1\u{202F}290,5\u{00A0}₽", 129050],
    'plain integer' => ['12990', 1299000],
    'zero' => ['0', 0],
    'trailing spaces' => ['  15  ', 1500],
    'blank' => ['', null],
    'only spaces' => ["  \u{00A0} ", null],
    'null' => [null, null],
]);

it('rejects bad prices', function (string $input) {
    expect(fn () => (new ValueParser)->price($input))->toThrow(ValueParseException::class);
})->with(['abc', '-5', '12,3,4', '1.290,50', '1e5', '10000000000000']);

it('parses stock', function (?string $input, ?int $expected) {
    expect((new ValueParser)->stock($input))->toBe($expected);
})->with([
    ['15', 15],
    ['15.0', 15],
    ['15,00', 15],
    ['1 500', 1500],
    ['0', 0],
    ['', null],
    [null, null],
]);

it('rejects bad stock', function (string $input) {
    expect(fn () => (new ValueParser)->stock($input))->toThrow(ValueParseException::class);
})->with(['1,5', '-1', 'abc', '1.5', '99999999999']);

it('parses booleans', function (?string $input, ?bool $expected) {
    expect((new ValueParser)->bool($input))->toBe($expected);
})->with([
    ['да', true], ['Да', true], ['ДА', true], ['нет', false], ['Нет', false],
    ['1', true], ['0', false], ['true', true], ['FALSE', false], ['TRUE', true],
    ['+', true], ['−', false], ['-', false], ['yes', true], ['No', false],
    ['', null], [null, null], ['  да ', true],
]);

it('rejects unknown booleans', function () {
    (new ValueParser)->bool('возможно');
})->throws(ValueParseException::class);

it('parses sales mode words', function (?string $input, SalesMode|string|null $expected) {
    expect((new ValueParser)->salesMode($input))->toBe($expected);
})->with([
    ['корзина', SalesMode::Cart],
    ['Резерв', SalesMode::Reservation],
    ['корзина и резерв', SalesMode::Both],
    ['  Корзина   и  Резерв ', SalesMode::Both],
    ['заявка', SalesMode::Inquiry],
    ['витрина', SalesMode::Showcase],
    ['по умолчанию', ValueParser::SALES_MODE_DEFAULT],
    ['', null],
]);

it('rejects unknown sales mode', function () {
    (new ValueParser)->salesMode('почтой');
})->throws(ValueParseException::class);

it('parses integers', function () {
    $parser = new ValueParser;

    expect($parser->integer('10'))->toBe(10)
        ->and($parser->integer('-5'))->toBe(-5)
        ->and($parser->integer('−5'))->toBe(-5)
        ->and($parser->integer('3.0'))->toBe(3)
        ->and($parser->integer(''))->toBeNull()
        ->and(fn () => $parser->integer('1.5'))->toThrow(ValueParseException::class);
});

it('splits photos by ; , and newlines', function () {
    $parser = new ValueParser;

    expect($parser->photos("a1.jpg; a2.jpg,a3.jpg\nhttps://site.ru/a4.jpg\r\n a1.jpg ;;"))
        ->toBe(['a1.jpg', 'a2.jpg', 'a3.jpg', 'https://site.ru/a4.jpg'])
        ->and($parser->photos(null))->toBe([])
        ->and($parser->photos('  '))->toBe([]);
});

it('splits category paths by the chosen separator', function () {
    $parser = new ValueParser;

    expect($parser->categoryPath('Электроника / Телефоны /  Смартфоны'))->toBe(['Электроника', 'Телефоны', 'Смартфоны'])
        ->and($parser->categoryPath('Электроника > Телефоны', '>'))->toBe(['Электроника', 'Телефоны'])
        ->and($parser->categoryPath(' | A||B | ', '|'))->toBe(['A', 'B'])
        ->and($parser->categoryPath('A / B', '>'))->toBe(['A / B'])
        ->and($parser->categoryPath(null))->toBe([])
        ->and($parser->categoryPath(' / '))->toBe([]);
});
