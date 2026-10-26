<?php

declare(strict_types=1);

use App\Support\PhoneNormalizer;

it('normalizes russian phone formats to +7XXXXXXXXXX', function (?string $input, ?string $expected) {
    expect(PhoneNormalizer::normalize($input))->toBe($expected);
})->with([
    ['8 (999) 123-45-67', '+79991234567'],
    ['+7 999 1234567', '+79991234567'],
    ['9991234567', '+79991234567'],
    ['7 999 123 45 67', '+79991234567'],
    ['+7(495)123-45-67', '+74951234567'],
    ['   +7-999-123-45-67  ', '+79991234567'],
    ['+7 999 123-45-6', null],
    ['+7 999 123-45-678', null],
    ['+1 999 123 45 67', null],
    ['2991234567', null],
    ['abc', null],
    ['', null],
    [null, null],
]);
