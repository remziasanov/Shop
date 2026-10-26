<?php

declare(strict_types=1);

use App\Exceptions\ImportFileException;
use App\Services\Import\ImportReader;
use Tests\Support\ImportFiles;

function readAll(string $path, ?string $sheet = null): array
{
    return iterator_to_array((new ImportReader)->open($path, $sheet));
}

it('reads a Windows-1251 CSV with semicolons', function () {
    $path = ImportFiles::csv([
        ['Артикул', 'Название', 'Цена'],
        ['A-1', 'Ёжик «Тест»', '1 290,50'],
        ['A-2', 'Строка; с разделителем', '12 990'],
    ], ';', 'Windows-1251');

    expect(mb_check_encoding((string) file_get_contents($path), 'UTF-8'))->toBeFalse();

    $reader = new ImportReader;

    expect($reader->sheets($path))->toBe(['CSV'])
        ->and(readAll($path))->toBe([
            1 => ['Артикул', 'Название', 'Цена'],
            2 => ['A-1', 'Ёжик «Тест»', '1 290,50'],
            3 => ['A-2', 'Строка; с разделителем', '12 990'],
        ]);

    unlink($path);
});

it('reads a UTF-8 CSV with BOM and commas', function () {
    $path = ImportFiles::csv([
        ['Артикул', 'Название', 'Цена'],
        ['A-1', 'Стол, большой', '100'],
    ], ',', 'UTF-8', bom: true);

    expect(str_starts_with((string) file_get_contents($path), "\xEF\xBB\xBF"))->toBeTrue()
        ->and(readAll($path))->toBe([
            1 => ['Артикул', 'Название', 'Цена'],
            2 => ['A-1', 'Стол, большой', '100'],
        ]);

    unlink($path);
});

it('reads a plain UTF-8 CSV and skips blank rows keeping row numbers', function () {
    $path = ImportFiles::csv([['sku', 'name'], ['', ''], ['A', 'B'], ['', ''], ['', '']]);

    expect(readAll($path))->toBe([1 => ['sku', 'name'], 3 => ['A', 'B']]);

    unlink($path);
});

it('reads multi-line quoted CSV cells', function () {
    $path = ImportFiles::csv([['sku', 'description'], ['A', "line1\nline2"]]);

    expect(readAll($path)[2])->toBe(['A', "line1\nline2"]);

    unlink($path);
});

it('removes the transcoded temp file after reading', function () {
    $before = glob(sys_get_temp_dir().'/shopimp*') ?: [];
    $path = ImportFiles::csv([['Артикул'], ['A']], ';', 'Windows-1251');

    foreach ((new ImportReader)->open($path) as $row) {
        break;
    }

    gc_collect_cycles();
    expect(glob(sys_get_temp_dir().'/shopimp*') ?: [])->toBe($before);

    unlink($path);
});

it('reads xlsx sheets by name and lists them', function () {
    $path = ImportFiles::xlsx([
        'Товары' => [['Артикул', 'Цена', 'Остаток'], ['A-1', 12990, 15], ['A-2', 1290.5, 0]],
        'Категории' => [['Путь*', 'Описание'], ['Мебель / Столы', 'Все столы']],
    ]);

    $reader = new ImportReader;

    expect($reader->sheets($path))->toBe(['Товары', 'Категории'])
        ->and($reader->hasSheet($path, 'Категории'))->toBeTrue()
        ->and($reader->hasSheet($path, 'Справка'))->toBeFalse()
        ->and($reader->guessProductSheet($reader->sheets($path)))->toBe('Товары')
        ->and(readAll($path))->toBe([
            1 => ['Артикул', 'Цена', 'Остаток'],
            2 => ['A-1', '12990', '15'],
            3 => ['A-2', '1290.5', '0'],
        ])
        ->and(readAll($path, 'Категории'))->toBe([
            1 => ['Путь*', 'Описание'],
            2 => ['Мебель / Столы', 'Все столы'],
        ])
        ->and($reader->headers($path, 'Категории'))->toBe(['Путь*', 'Описание']);

    unlink($path);
});

it('turns xlsx numbers into strings without float artifacts', function () {
    $path = ImportFiles::xlsx(['S' => [['h'], [0.1 + 0.2], [12990.0], [1290.5], [true], [-7.25], [1234567890123], [null], ['  text  ']]]);

    expect(array_values(readAll($path)))->toBe([['h'], ['0.3'], ['12990'], ['1290.5'], ['1'], ['-7.25'], ['1234567890123'], ['text']]);

    unlink($path);
});

it('formats xlsx dates as Y-m-d', function () {
    $path = ImportFiles::xlsx(['S' => [['d'], [ImportFiles::dateCell('2026-03-05 10:00:00')]]]);

    expect(readAll($path)[2])->toBe(['2026-03-05']);

    unlink($path);
});

it('keeps source row numbers across blank xlsx rows and drops trailing empties', function () {
    $path = ImportFiles::xlsx(['S' => [['a', 'b'], [null, null], ['x', null], ['y', 'z'], [null, null], [null, null]]]);

    expect(readAll($path))->toBe([1 => ['a', 'b'], 3 => ['x'], 4 => ['y', 'z']]);

    unlink($path);
});

it('fails with a readable error for an unknown sheet or a broken file', function () {
    $path = ImportFiles::xlsx(['S' => [['a']]]);

    expect(fn () => readAll($path, 'Нет такого'))->toThrow(ImportFileException::class);
    expect(fn () => (new ImportReader)->sheets('/nonexistent/file.xlsx'))->toThrow(ImportFileException::class);

    $broken = ImportFiles::tempPath('xlsx');
    file_put_contents($broken, "PK\x03\x04garbage");

    expect(fn () => (new ImportReader)->sheets($broken))->toThrow(ImportFileException::class);

    $xls = ImportFiles::tempPath('xls');
    file_put_contents($xls, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1rest");

    expect(fn () => (new ImportReader)->sheets($xls))->toThrow(ImportFileException::class);

    unlink($path);
    unlink($broken);
    unlink($xls);
});

it('returns sample rows for the mapping step', function () {
    $path = ImportFiles::productsCsv(30);

    $sample = (new ImportReader)->sample($path, null, 3);

    expect($sample['headers'])->toBe(['Артикул', 'Название', 'Категория', 'Цена', 'Остаток'])
        ->and($sample['rows'])->toHaveCount(3)
        ->and(array_key_first($sample['rows']))->toBe(2);

    unlink($path);
});
