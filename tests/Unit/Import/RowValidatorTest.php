<?php

declare(strict_types=1);

use App\Enums\ImportMode;
use App\Services\Import\MappedRow;
use App\Services\Import\RowValidationResult;
use App\Services\Import\RowValidator;

function validateRow(array $values, ImportMode $mode = ImportMode::CreateUpdate, bool $exists = false, array &$seen = [], array $attributes = [], int $row = 2): RowValidationResult
{
    return (new RowValidator)->validate(new MappedRow($values, $attributes), $mode, $exists, $seen, '/', $row);
}

function errorFields(RowValidationResult $result): array
{
    return array_map(fn ($issue) => $issue->field, $result->errors);
}

$valid = ['sku' => 'A-1', 'name' => 'Стол', 'category' => 'Мебель / Столы', 'price' => '1 290,50', 'stock' => '15'];

it('accepts a valid new row and normalizes values', function () use ($valid) {
    $result = validateRow($valid + ['photos' => 'a.jpg; b.jpg', 'active' => 'да', 'sales_mode' => 'резерв', 'sort' => '5']);

    expect($result->isValid())->toBeTrue()
        ->and($result->action)->toBe('create')
        ->and($result->values)->toEqual([
            'sku' => 'A-1', 'price' => 129050, 'stock' => 15, 'category' => ['Мебель', 'Столы'], 'name' => 'Стол',
            'photos' => ['a.jpg', 'b.jpg'], 'active' => true, 'sort' => 5, 'sales_mode' => 'reservation',
        ]);
});

it('requires a sku', function () use ($valid) {
    expect(errorFields(validateRow(['sku' => null] + $valid)))->toBe(['sku'])
        ->and(errorFields(validateRow(['sku' => '   '] + $valid)))->toBe(['sku']);
});

it('requires name, category and price for new products only', function () {
    $new = validateRow(['sku' => 'A']);

    expect(errorFields($new))->toBe(['name', 'category', 'price'])
        ->and($new->action)->toBe('error');

    $existing = validateRow(['sku' => 'A'], exists: true);

    expect($existing->isValid())->toBeTrue()->and($existing->action)->toBe('update');
});

it('treats blank cells of required columns as missing for new products', function () {
    $result = validateRow(['sku' => 'A', 'name' => null, 'category' => ' / ', 'price' => '']);

    expect(errorFields($result))->toBe(['name', 'category', 'price']);
});

it('validates price and stock', function (string $field, string $value, bool $ok) use ($valid) {
    expect(validateRow([$field => $value] + $valid)->isValid())->toBe($ok);
})->with([
    ['price', '-1', false], ['price', 'abc', false], ['price', '0', true], ['price', '1 290 ₽', true],
    ['stock', '1,5', false], ['stock', '-3', false], ['stock', '15.0', true], ['stock', '', true],
    ['cost_price', 'x', false], ['old_price', '2 000', true],
]);

it('validates booleans and sales mode', function () use ($valid) {
    expect(errorFields(validateRow(['active' => 'возможно', 'featured' => 'да', 'new' => '+', 'price_on_request' => '−'] + $valid)))->toBe(['active'])
        ->and(errorFields(validateRow(['sales_mode' => 'почтой'] + $valid)))->toBe(['sales_mode']);
});

it('keeps explicit default sales mode distinct from a blank cell', function () use ($valid) {
    expect(validateRow(['sales_mode' => 'по умолчанию'] + $valid)->values['sales_mode'])->toBe('default')
        ->and(validateRow(['sales_mode' => ''] + $valid)->values['sales_mode'])->toBeNull();
});

it('checks field lengths', function (string $field, int $max) use ($valid) {
    expect(validateRow([$field => str_repeat('я', $max)] + $valid)->isValid())->toBeTrue()
        ->and(errorFields(validateRow([$field => str_repeat('я', $max + 1)] + $valid)))->toContain($field);
})->with([
    ['sku', 100], ['name', 500], ['brand', 255], ['unit', 20], ['meta_title', 255], ['meta_description', 255], ['short_description', 500],
]);

it('flags the second and later occurrences of a sku (IM-07)', function () use ($valid) {
    $seen = [];

    $first = validateRow($valid, seen: $seen, row: 2);
    $second = validateRow($valid, seen: $seen, row: 3);
    $third = validateRow(['sku' => 'a-1'] + $valid, seen: $seen, row: 4);

    expect($first->isValid())->toBeTrue()
        ->and($second->isValid())->toBeFalse()
        ->and(errorFields($second))->toBe(['sku'])
        ->and($second->errors[0]->message)->toContain('2')
        ->and($third->isValid())->toBeFalse();
});

it('registers the sku of an invalid row as seen', function () {
    $seen = [];

    validateRow(['sku' => 'X', 'price' => 'bad'], seen: $seen, row: 2);

    expect(validateRow(['sku' => 'X'], exists: true, seen: $seen, row: 3)->isValid())->toBeFalse();
});

it('considers only sku, prices and stock in prices_stock mode', function () {
    $result = validateRow(
        ['sku' => 'A', 'price' => '100', 'stock' => '5', 'name' => str_repeat('я', 900), 'active' => 'bad', 'category' => ''],
        ImportMode::PricesStock,
        exists: true,
    );

    expect($result->isValid())->toBeTrue()
        ->and($result->values)->toBe(['sku' => 'A', 'price' => 10000, 'stock' => 5])
        ->and($result->action)->toBe('update');
});

it('skips unknown products in prices_stock mode and still checks prices', function () {
    $skip = validateRow(['sku' => 'NEW', 'price' => '100'], ImportMode::PricesStock, exists: false);
    $bad = validateRow(['sku' => 'NEW', 'price' => 'oops'], ImportMode::PricesStock, exists: false);

    expect($skip->action)->toBe('skip')->and($skip->isValid())->toBeTrue()
        ->and($bad->action)->toBe('error');
});

it('warns when prices_stock row has nothing to update', function () {
    $result = validateRow(['sku' => 'A'], ImportMode::PricesStock, exists: true);

    expect($result->isValid())->toBeTrue()->and($result->warnings)->toHaveCount(1);
});

it('warns when old price is not greater than price', function () use ($valid) {
    $result = validateRow(['old_price' => '1000'] + $valid);

    expect($result->isValid())->toBeTrue()->and($result->warnings)->toHaveCount(1);
});

it('limits category depth and attribute lengths', function () use ($valid) {
    expect(errorFields(validateRow(['category' => 'a/b/c/d/e'] + $valid)))->toBe(['category']);

    $result = validateRow($valid, attributes: [['name' => 'Цвет', 'value' => 'чёрный'], ['name' => 'Описание', 'value' => str_repeat('я', 1001)]]);

    expect($result->attributes)->toBe([['name' => 'Цвет', 'value' => 'чёрный']])
        ->and($result->errors)->toHaveCount(1);
});

it('validates rows of the categories sheet', function () {
    $validator = new RowValidator;

    $ok = $validator->validateCategory(['path' => 'Электроника / Телефоны', 'sort' => '10', 'active' => 'нет', 'sales_mode' => 'витрина', 'description' => 'Текст']);

    expect($ok['errors'])->toBe([])
        ->and($ok['values'])->toBe(['path' => ['Электроника', 'Телефоны'], 'description' => 'Текст', 'sort' => 10, 'active' => false, 'sales_mode' => 'showcase']);

    $bad = $validator->validateCategory(['path' => ' ', 'sort' => 'x', 'active' => 'maybe']);

    expect(array_map(fn ($i) => $i->field, $bad['errors']))->toBe(['path', 'sort', 'active']);
});
