<?php

declare(strict_types=1);

use App\Support\Money;

it('parses rubles input into kopecks', function (string|int|float $input, int $expected) {
    expect(Money::fromRubles($input))->toBe($expected);
})->with([
    'spaces and comma' => ['1 290,50', 129050],
    'dot with one decimal' => ['1290.5', 129050],
    'currency sign' => ['1 290 ₽', 129000],
    'narrow nbsp' => ["1\u{202F}290\u{00A0}₽", 129000],
    'int zero' => [0, 0],
    'int rubles' => [15, 1500],
    'float' => [12.5, 1250],
    'float precision' => [0.1 + 0.2, 30],
    'empty' => ['', 0],
    'rounds half up' => ['10.005', 1001],
    'negative' => ['-5,25', -525],
]);

it('rejects garbage', function () {
    Money::fromRubles('abc');
})->throws(InvalidArgumentException::class);

it('converts kopecks to a rubles string', function () {
    expect(Money::toRubles(129050))->toBe('1290.50')
        ->and(Money::toRubles(0))->toBe('0.00')
        ->and(Money::toRubles(5))->toBe('0.05')
        ->and(Money::toRubles(-150))->toBe('-1.50');
});

it('formats kopecks for display', function () {
    expect(Money::format(129000))->toBe("1\u{202F}290\u{00A0}₽")
        ->and(Money::format(129050))->toBe("1\u{202F}290,50\u{00A0}₽")
        ->and(Money::format(0))->toBe("0\u{00A0}₽")
        ->and(Money::format(5))->toBe("0,05\u{00A0}₽")
        ->and(Money::format(123456700))->toBe("1\u{202F}234\u{202F}567\u{00A0}₽");
});
