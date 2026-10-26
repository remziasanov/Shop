<?php

declare(strict_types=1);

use App\Services\Search\SearchNormalizer;

beforeEach(function (): void {
    $this->normalizer = new SearchNormalizer;
});

it('lowercases, replaces yo and collapses whitespace', function (): void {
    expect($this->normalizer->normalize("  ЁЛКА   Новогодняя \t Ёмкая\n"))->toBe('елка новогодняя емкая');
});

it('splits into distinct words', function (): void {
    expect($this->normalizer->words('Стол  стол ДУБОВЫЙ'))->toBe(['стол', 'дубовый'])
        ->and($this->normalizer->words('   '))->toBe([]);
});

it('escapes LIKE wildcards', function (): void {
    expect($this->normalizer->escapeLike('50%_off!'))->toBe('50!%!_off!!');
});

it('builds search text truncated to 1000 characters without breaking multibyte chars', function (): void {
    $text = $this->normalizer->buildSearchText(str_repeat('Ёж', 700), 'SKU-1', '');

    expect(mb_strlen($text))->toBeLessThanOrEqual(1000)
        ->and(mb_check_encoding($text, 'UTF-8'))->toBeTrue()
        ->and($this->normalizer->buildSearchText('Кресло Ёлка', 'AB-1', 'Север'))->toBe('кресло елка ab-1 север');
});
