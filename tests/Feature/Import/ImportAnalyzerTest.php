<?php

declare(strict_types=1);

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Exceptions\ImportFileException;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Services\Import\ImportAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFiles;

beforeEach(function () {
    Storage::fake('local');
});

function makeImport(string $sourcePath, array $attributes = []): Import
{
    $import = Import::factory()->create($attributes + ['file_path' => 'pending']);
    $relative = 'imports/'.$import->id.'/source.'.pathinfo($sourcePath, PATHINFO_EXTENSION);

    Storage::disk('local')->put($relative, (string) file_get_contents($sourcePath));
    $import->update(['file_path' => $relative]);

    return $import->fresh();
}

function stagedLines(Import $import): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        file(Storage::disk('local')->path($import->fresh()->staging_path), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
}

it('stages a 450-row CSV in 3 chunks with offsets and a summary', function () {
    $existing = Product::factory()->count(2)->create();
    Category::factory()->create();

    // Rows 1..450: row 50k has a bad price (9 rows), every 100th (4 rows) repeats the first SKU.
    $path = ImportFiles::productsCsv(450, [$existing[0]->sku, $existing[1]->sku]);
    $import = makeImport($path, ['chunk_size' => 200]);

    $result = app(ImportAnalyzer::class)->analyze($import);
    $import = $result->fresh();

    $summary = $import->analysis['summary'];

    // Errors: bad prices at 50,150,250,350,450 (100,200,300,400 are duplicates of row 1; 100/200.. are not multiples
    // of 50 conflicts: 100,200,300,400 are multiples of 50 too, so they carry both errors but count once).
    expect($import->status)->toBe(ImportStatus::Analyzed)
        ->and($import->rows_total)->toBe(450)
        ->and($import->chunks_total)->toBe(3)
        ->and($import->chunk_offsets)->toHaveCount(3)
        ->and($import->chunk_offsets[0])->toBe(0)
        ->and($summary['rows_total'])->toBe(450)
        ->and($summary['errors'])->toBe(9)
        ->and($summary['update'])->toBe(2)
        ->and($summary['create'] + $summary['update'] + $summary['skip'] + $summary['errors'])->toBe(450);

    // Each chunk offset points at the first line of its chunk.
    $handle = fopen(Storage::disk('local')->path($import->staging_path), 'rb');

    foreach ([0 => 1, 1 => 201, 2 => 401] as $chunk => $expectedFirstRow) {
        fseek($handle, $import->chunk_offsets[$chunk]);
        $line = json_decode((string) fgets($handle), true);
        // Row numbers are file rows: data row N is file row N + 1.
        expect($line['row'])->toBe($expectedFirstRow + 1);
    }

    fclose($handle);

    $lines = stagedLines($import);
    expect($lines)->toHaveCount(450)
        ->and($lines[0]['action'])->toBe('update')
        ->and($lines[0]['values']['sku'])->toBe($existing[0]->sku)
        ->and($lines[2]['values'])->toMatchArray(['price' => 100350 + 0, 'stock' => 3, 'category' => ['Мебель', 'Столы']])
        ->and($lines[49]['errors'][0]['field'])->toBe('price')
        ->and($lines[49]['errors'][0]['column'])->toBe('Цена')
        ->and($lines[99]['errors'][0]['field'])->toBe('sku');

    expect($import->analysis['preview'])->toHaveCount(20)
        ->and($import->analysis['headers'])->toBe(['Артикул', 'Название', 'Категория', 'Цена', 'Остаток'])
        ->and($import->mapping)->toBe([0 => 'sku', 1 => 'name', 2 => 'category', 3 => 'price', 4 => 'stock'])
        ->and($import->error_count)->toBe(0);

});

it('counts create, update, skip and errors by mode', function () {
    Product::factory()->create(['sku' => 'OLD-1']);
    Product::factory()->create(['sku' => 'GONE-1'])->delete();

    $rows = [
        ['Артикул', 'Название', 'Категория', 'Цена'],
        ['OLD-1', '', '', '100'],
        ['NEW-1', 'Новый', 'А / Б', '200'],
        ['GONE-1', '', '', '300'],
        ['BAD', 'Без цены', 'А', ''],
        ['NEW-1', 'Дубль', 'А', '1'],
    ];
    $path = ImportFiles::csv($rows);

    $created = app(ImportAnalyzer::class)->analyze(makeImport($path))->fresh();
    expect($created->analysis['summary'])->toMatchArray(['create' => 1, 'update' => 2, 'skip' => 0, 'errors' => 2, 'restored' => 1, 'rows_total' => 5]);

    $prices = app(ImportAnalyzer::class)->analyze(makeImport($path, ['mode' => ImportMode::PricesStock]))->fresh();
    expect($prices->analysis['summary'])->toMatchArray(['create' => 0, 'update' => 2, 'skip' => 2, 'errors' => 1]);
});

it('stages the categories sheet of an xlsx and reads products from the first other sheet', function () {
    $path = ImportFiles::xlsx([
        'Товары' => [['Артикул', 'Название', 'Категория', 'Цена', 'Х: Цвет'], ['A-1', 'Стол', 'Мебель / Столы', 1290.5, 'чёрный']],
        'Категории' => [['Путь*', 'Описание', 'Порядок', 'Активна'], ['Мебель / Столы', 'Все столы', 5, 'нет'], ['', 'нет пути', '', ''], ['Мебель', null, 'x', null]],
    ]);

    $import = app(ImportAnalyzer::class)->analyze(makeImport($path))->fresh();

    expect($import->sheet)->toBe('Товары')
        ->and($import->rows_total)->toBe(1)
        ->and($import->categories_staging_path)->toBe('imports/'.$import->id.'/categories.jsonl')
        ->and($import->analysis['summary'])->toMatchArray(['create' => 1, 'categories_rows' => 3, 'categories_errors' => 2]);

    $categories = array_map(fn ($l) => json_decode($l, true), file(Storage::disk('local')->path($import->categories_staging_path), FILE_IGNORE_NEW_LINES));

    expect($categories[0])->toMatchArray(['row' => 2, 'path' => ['Мебель', 'Столы'], 'description' => 'Все столы', 'sort' => 5, 'active' => false, 'errors' => []])
        ->and($categories[1]['errors'][0]['field'])->toBe('path')
        ->and($categories[2]['errors'][0]['field'])->toBe('sort');

    $lines = stagedLines($import);

    expect($lines[0]['values'])->toMatchArray(['sku' => 'A-1', 'price' => 129050])
        ->and($lines[0]['attributes'])->toBe([['name' => 'Цвет', 'value' => 'чёрный']]);
});

it('uses an explicit mapping and reports mapping duplicates', function () {
    $path = ImportFiles::csv([['Код', 'Что-то', 'Цена', 'Розничная цена'], ['A-1', 'Стол', '100', '999']]);

    $import = app(ImportAnalyzer::class)->analyze(makeImport($path, ['mapping' => [0 => 'sku', 1 => 'name', 2 => null, 3 => 'price']]))->fresh();

    expect(stagedLines($import)[0]['values'])->toMatchArray(['sku' => 'A-1', 'name' => 'Стол', 'price' => 99900]);

    $auto = app(ImportAnalyzer::class)->analyze(makeImport($path))->fresh();

    expect($auto->analysis['mapping_warnings'])->toHaveCount(1)
        ->and(stagedLines($auto)[0]['values']['price'])->toBe(10000);
});

it('fails with a readable message when there is no sku column or the file is empty', function () {
    $noSku = makeImport(ImportFiles::csv([['Название'], ['x']]));

    expect(fn () => app(ImportAnalyzer::class)->analyze($noSku))->toThrow(ImportFileException::class);
    expect($noSku->fresh()->status)->toBe(ImportStatus::Failed)
        ->and($noSku->fresh()->failure_message)->toContain('Артикул');

    $empty = makeImport(ImportFiles::csv([]));

    expect(fn () => app(ImportAnalyzer::class)->analyze($empty))->toThrow(ImportFileException::class);
});

it('re-analysis replaces the previous staging files', function () {
    $import = makeImport(ImportFiles::productsCsv(10));
    app(ImportAnalyzer::class)->analyze($import);
    $second = app(ImportAnalyzer::class)->analyze($import->fresh())->fresh();

    expect(stagedLines($second))->toHaveCount(10)->and($second->chunks_total)->toBe(1);
});

it('handles a 10 000-row CSV with bounded memory', function () {
    $path = ImportFiles::productsCsv(10_000);
    $import = makeImport($path);
    unlink($path);

    gc_collect_cycles();
    $before = memory_get_usage(true);
    memory_reset_peak_usage();
    $start = microtime(true);

    $analyzed = app(ImportAnalyzer::class)->analyze($import)->fresh();

    $elapsed = microtime(true) - $start;
    $growth = memory_get_peak_usage(true) - $before;

    expect($analyzed->rows_total)->toBe(10_000)
        ->and($analyzed->chunks_total)->toBe(50)
        ->and($growth)->toBeLessThan(64 * 1024 * 1024)
        ->and($elapsed)->toBeLessThan(60.0);
});
