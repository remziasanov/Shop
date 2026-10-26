<?php

declare(strict_types=1);

use App\Models\Import;
use App\Services\Import\ColumnMapper;
use App\Services\Import\MappingMemory;

it('falls back to auto mapping without history', function () {
    expect(app(MappingMemory::class)->suggest(['Артикул', 'Название', 'Что-то']))
        ->toBe([0 => 'sku', 1 => 'name', 2 => null]);
});

it('reuses assignments of the latest import with overlapping headers', function () {
    $memory = app(MappingMemory::class);
    $headers = ['Код 1С', 'Номенклатура', 'Розница', 'Склад'];

    $import = Import::factory()->create();
    $memory->remember($import, $headers, [0 => 'sku', 1 => 'name', 2 => 'price', 3 => 'stock']);

    expect($import->fresh()->mapping)->toBe([0 => 'sku', 1 => 'name', 2 => 'price', 3 => 'stock'])
        ->and($import->fresh()->analysis['headers'])->toBe($headers);

    // Same headers, other case/spaces/order, plus an unknown one.
    expect($memory->suggest(['номенклатура', 'КОД  1С', 'розница', 'Склад', 'Артикул']))
        ->toBe([0 => 'name', 1 => 'sku', 2 => 'price', 3 => 'stock', 4 => null]);
});

it('remembers ignored columns and keeps one column per field', function () {
    $memory = app(MappingMemory::class);

    $memory->remember(Import::factory()->create(), ['Артикул', 'Цена', 'Комментарий'], [0 => 'sku', 1 => null, 2 => 'price']);

    // "Цена" was explicitly ignored last time, "Комментарий" went to price: the saved choice wins and stays unique.
    expect($memory->suggest(['Артикул', 'Цена', 'Комментарий']))->toBe([0 => 'sku', 1 => null, 2 => 'price']);
});

it('ignores history with too little header overlap and takes the newest match', function () {
    $memory = app(MappingMemory::class);

    $old = Import::factory()->create();
    $memory->remember($old, ['A', 'B', 'C'], [0 => 'name', 1 => 'sku', 2 => 'price']);

    expect($memory->suggest(['Артикул', 'Название', 'A']))->toBe([0 => 'sku', 1 => 'name', 2 => null]);

    $new = Import::factory()->create();
    $memory->remember($new, ['A', 'B', 'C'], [0 => 'brand', 1 => 'sku', 2 => 'price']);

    expect($memory->suggest(['A', 'B', 'C']))->toBe([0 => 'brand', 1 => 'sku', 2 => 'price'])
        ->and($memory->suggest(['A', 'B', 'C'], $new->id))->toBe([0 => 'name', 1 => 'sku', 2 => 'price']);
});

it('deduplicates a user-edited mapping when remembering', function () {
    $import = Import::factory()->create();
    app(MappingMemory::class)->remember($import, ['a', 'b'], [0 => 'sku', 1 => 'sku']);

    expect($import->fresh()->mapping)->toBe([0 => 'sku', 1 => null])
        ->and(app(ColumnMapper::class)->autoMap(['a']))->toBe([0 => null]);
});
