<?php

declare(strict_types=1);

use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Models\User;
use App\Services\Demo\DemoCatalogSeeder;
use App\Services\Demo\DemoImportBuilder;
use App\Services\Export\CatalogExporter;
use App\Services\Export\ImportTemplateBuilder;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportReader;
use Tests\Support\ImportFiles;

/**
 * @return array<int, list<string|null>>
 */
function sheetRows(string $path, string $sheet): array
{
    return iterator_to_array((new ImportReader)->open($path, $sheet));
}

it('builds a template with three sheets, all columns and an example row', function (): void {
    $path = ImportFiles::tempPath('xlsx');
    app(ImportTemplateBuilder::class)->build($path);

    $reader = new ImportReader;
    expect($reader->sheets($path))->toBe(['Товары', 'Категории', 'Справка']);

    $products = sheetRows($path, 'Товары');
    $headers = $products[1];

    expect($headers)->toBe(CatalogExporter::productHeaders(['Цвет', 'Материал']))
        ->and($products)->toHaveCount(2)
        ->and($products[2][0])->toBe('ABC-123')
        ->and(array_slice($headers, -2))->toBe(['Х: Цвет', 'Х: Материал']);

    $mapping = app(ColumnMapper::class)->autoMap($headers);
    expect(array_slice($mapping, 0, 20))->toBe(array_map(fn (ImportField $f): string => $f->value, ImportField::cases()))
        ->and(array_slice($mapping, 20))->toBe(['attr:Цвет', 'attr:Материал']);

    $categories = sheetRows($path, 'Категории');
    expect($categories[1])->toBe(CatalogExporter::categoryHeaders())
        ->and($categories[4][0])->toBe('Электроника / Телефоны / Смартфоны');

    $help = sheetRows($path, 'Справка');
    $names = array_map(fn (array $row): ?string => $row[0], $help);

    foreach (ImportField::cases() as $field) {
        expect($names)->toContain($field->label());
    }

    expect($names)->toContain('Х: <название>')
        ->and($help[2])->toHaveCount(4)
        ->and($help[2][1])->toBe('да')
        ->and($help[3][1])->toBe('для новых товаров');

    unlink($path);
});

it('writes the template with shop:make-template', function (): void {
    $path = ImportFiles::tempPath('xlsx');

    $this->artisan('shop:make-template', ['--path' => $path])->assertSuccessful();

    expect(is_file($path))->toBeTrue()
        ->and((new ImportReader)->sheets($path))->toBe(['Товары', 'Категории', 'Справка']);

    unlink($path);
});

it('serves the template on the admin.import.template route to staff only', function (): void {
    $this->get(route('admin.import.template'))->assertRedirect();

    $response = $this->actingAs(User::factory()->manager()->create())->get(route('admin.import.template'));

    $response->assertOk()->assertDownload('import-template.xlsx');
});

it('generates the demo import file and a photo archive that contains every referenced photo', function (): void {
    $dir = sys_get_temp_dir().'/shopdemo-'.uniqid();

    $this->artisan('shop:make-demo-import', ['--dir' => $dir])->assertSuccessful();

    $rows = sheetRows($dir.'/import-demo.xlsx', 'Товары');
    $headers = $rows[1];
    $mapping = app(ColumnMapper::class)->autoMap($headers);
    $photoColumn = array_search('photos', $mapping, true);
    $categoryColumn = array_search('category', $mapping, true);
    $skuColumn = array_search('sku', $mapping, true);

    expect(count($rows) - 1)->toBeGreaterThanOrEqual(45)
        ->and(array_filter($mapping, fn ($m) => is_string($m) && str_starts_with($m, 'attr:')))->not->toBeEmpty();

    $zip = new ZipArchive;
    expect($zip->open($dir.'/import-demo-photos.zip'))->toBeTrue();
    $inZip = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $inZip[] = $zip->getNameIndex($i);
    }

    $levels = [];
    $skus = [];

    foreach (array_slice($rows, 1) as $row) {
        $levels[] = count(explode(' / ', (string) $row[$categoryColumn]));
        $skus[] = $row[$skuColumn];

        foreach (explode('; ', (string) $row[$photoColumn]) as $photo) {
            expect($inZip)->toContain($photo);
        }
    }

    expect(max($levels))->toBe(3)
        ->and(array_count_values($levels)[3])->toBeGreaterThan(25)
        ->and(array_unique($skus))->toHaveCount(count($skus))
        ->and($zip->numFiles)->toBeGreaterThanOrEqual(50)
        ->and(filesize($dir.'/import-demo-photos.zip'))->toBeLessThan(5 * 1024 * 1024);

    $info = getimagesizefromstring((string) $zip->getFromName('a1.jpg'));
    expect($info[0])->toBe(800)->and($info[1])->toBe(600);
    $zip->close();

    $categories = sheetRows($dir.'/import-demo.xlsx', CategorySheetField::SHEET_NAME);
    expect(count($categories) - 1)->toBe(count(DemoCatalogSeeder::CATEGORIES))
        ->and($categories[1])->toBe(CatalogExporter::categoryHeaders());

    foreach ([DemoImportBuilder::XLSX_NAME, DemoImportBuilder::ZIP_NAME] as $file) {
        unlink($dir.'/'.$file);
    }
    rmdir($dir);
});

it('ships the example files under examples/', function (): void {
    foreach (['import-template.xlsx', 'import-demo.xlsx', 'import-demo-photos.zip'] as $file) {
        expect(is_file(base_path('examples/'.$file)))->toBeTrue();
    }

    expect((new ImportReader)->sheets(base_path('examples/import-template.xlsx')))->toBe(['Товары', 'Категории', 'Справка'])
        ->and(count(sheetRows(base_path('examples/import-demo.xlsx'), 'Товары')) - 1)->toBeGreaterThanOrEqual(45);
});
