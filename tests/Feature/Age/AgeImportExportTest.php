<?php

declare(strict_types=1);

use App\Enums\AgeRestriction;
use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Services\Export\CatalogExporter;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportReader;
use App\Services\Import\ValueParseException;
use App\Services\Import\ValueParser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
});

/**
 * @param  list<list<string|int|float|bool|null>>  $products
 * @param  list<list<string|int|float|bool|null>>|null  $categories
 */
function importAge(array $products, ?array $categories = null, array $attributes = []): Import
{
    $sheets = ['Товары' => $products];

    if ($categories !== null) {
        $sheets['Категории'] = $categories;
    }

    return ImportHarness::run(ImportHarness::analyzed(ImportFiles::xlsx($sheets), $attributes));
}

it('maps the «Возраст» column by its synonyms on both sheets (AG-13)', function (string $header) {
    $mapper = new ColumnMapper;

    expect($mapper->autoMap(['Артикул', $header]))->toBe([0 => 'sku', 1 => ImportField::Age->value])
        ->and($mapper->autoMapCategories(['Путь', $header]))->toBe([0 => 'path', 1 => CategorySheetField::Age->value]);
})->with(['Возраст', 'возрастное ограничение', 'Возрастное  Ограничение', 'age', 'AGE']);

it('parses the age cell: 16, 18, 0 to clear, blank to inherit (AG-13)', function () {
    $parser = new ValueParser;

    expect($parser->age('16'))->toBe(16)
        ->and($parser->age(' 18 '))->toBe(18)
        ->and($parser->age('18+'))->toBe(18)
        ->and($parser->age('0'))->toBe(0)
        ->and($parser->age(''))->toBeNull()
        ->and($parser->age(null))->toBeNull();

    expect(fn () => $parser->age('21'))->toThrow(ValueParseException::class);
    expect(fn () => $parser->age('abc'))->toThrow(ValueParseException::class);
});

it('imports the age of new products and updates existing ones (AG-13)', function () {
    Product::factory()->inCategory(Category::factory()->create(['name' => 'Прочее']))->create(['sku' => 'OLD-1', 'age_restriction' => AgeRestriction::Age16]);

    $import = importAge([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Возраст'],
        ['NEW-16', 'Пиво', 'Напитки', 100, '16'],
        ['NEW-18', 'Сигары', 'Напитки', 200, 18],
        ['NEW-NO', 'Вода', 'Напитки', 50, null],
        ['OLD-1', null, null, null, '18'],
    ]);

    expect($import->status)->toBe(ImportStatus::Completed)->and($import->error_count)->toBe(0);

    $age = fn (string $sku): ?AgeRestriction => Product::query()->where('sku', $sku)->firstOrFail()->age_restriction;

    expect($age('NEW-16'))->toBe(AgeRestriction::Age16)
        ->and($age('NEW-18'))->toBe(AgeRestriction::Age18)
        ->and($age('NEW-NO'))->toBeNull()
        ->and($age('OLD-1'))->toBe(AgeRestriction::Age18);
});

it('clears the own value with 0 and keeps it on a blank cell by default (AG-13)', function () {
    $category = Category::factory()->create(['name' => 'Прочее']);
    Product::factory()->inCategory($category)->create(['sku' => 'A-1', 'age_restriction' => AgeRestriction::Age18]);
    Product::factory()->inCategory($category)->create(['sku' => 'A-2', 'age_restriction' => AgeRestriction::Age18]);

    importAge([
        ['Артикул', 'Возраст'],
        ['A-1', '0'],
        ['A-2', null],
    ]);

    expect(Product::query()->where('sku', 'A-1')->firstOrFail()->age_restriction)->toBeNull()
        ->and(Product::query()->where('sku', 'A-2')->firstOrFail()->age_restriction)->toBe(AgeRestriction::Age18);
});

it('reports a wrong age value as a row error (AG-13)', function () {
    $import = importAge([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Возраст'],
        ['BAD-1', 'Товар', 'Раздел', 10, '21'],
    ]);

    expect($import->error_count)->toBe(1)
        ->and(Product::query()->where('sku', 'BAD-1')->exists())->toBeFalse();
});

it('imports the age of categories from the «Категории» sheet (AG-13)', function () {
    Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age16]);
    Category::factory()->create(['name' => 'Пиво', 'age_restriction' => AgeRestriction::Age18]);

    importAge(
        [['Артикул', 'Название', 'Категория', 'Цена'], ['P-1', 'Товар', 'Табак', 10]],
        [
            ['Путь', 'Возраст'],
            ['Алкоголь', '18'],
            ['Алкоголь / Вино', null],
            ['Табак', '0'],
            ['Пиво', null],
        ],
    );

    $level = fn (string $name): ?AgeRestriction => Category::query()->where('name', $name)->firstOrFail()->age_restriction;

    expect($level('Алкоголь'))->toBe(AgeRestriction::Age18)
        ->and($level('Вино'))->toBeNull()
        ->and($level('Табак'))->toBeNull()
        ->and($level('Пиво'))->toBe(AgeRestriction::Age18);
});

it('exports the own age of products and categories in the «Возраст» column (AG-13)', function () {
    $tobacco = Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age18]);
    $plain = Category::factory()->create(['name' => 'Мебель']);
    Product::factory()->inCategory($tobacco)->create(['sku' => 'INH-1']);
    Product::factory()->inCategory($tobacco)->create(['sku' => 'OWN-1', 'age_restriction' => AgeRestriction::Age16]);
    Product::factory()->inCategory($plain)->create(['sku' => 'NONE-1']);

    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);

    $reader = new ImportReader;
    $products = iterator_to_array($reader->open($path, 'Товары'));
    $headers = array_values($products[1]);
    $column = array_search('Возраст', $headers, true);
    $bySku = [];

    foreach (array_slice($products, 1) as $row) {
        $bySku[$row[0]] = $row[$column] ?? null;
    }

    expect($column)->not->toBeFalse()
        ->and($bySku['INH-1'])->toBeNull()
        ->and($bySku['OWN-1'])->toBe('16')
        ->and($bySku['NONE-1'])->toBeNull();

    $categories = iterator_to_array($reader->open($path, 'Категории'));
    $catColumn = array_search('Возраст', array_values($categories[1]), true);
    $byName = [];

    foreach (array_slice($categories, 1) as $row) {
        $byName[$row[0]] = $row[$catColumn] ?? null;
    }

    expect($byName['Табак'])->toBe('18')->and($byName['Мебель'])->toBeNull();
});

it('survives an export → import round trip unchanged (AG-13)', function () {
    $tobacco = Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age18]);
    Product::factory()->inCategory($tobacco)->create(['sku' => 'INH-1']);
    Product::factory()->inCategory($tobacco)->create(['sku' => 'OWN-1', 'age_restriction' => AgeRestriction::Age16]);

    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);
    $import = ImportHarness::run(ImportHarness::analyzed($path));

    expect($import->error_count)->toBe(0)
        ->and($import->unchanged_count)->toBe(2)
        ->and(Product::query()->where('sku', 'INH-1')->firstOrFail()->age_restriction)->toBeNull()
        ->and(Product::query()->where('sku', 'OWN-1')->firstOrFail()->age_restriction)->toBe(AgeRestriction::Age16)
        ->and(Category::query()->where('name', 'Табак')->firstOrFail()->age_restriction)->toBe(AgeRestriction::Age18);
});
