<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\ImportField;
use App\Enums\SalesMode;
use App\Jobs\ExportCatalog;
use App\Models\Category;
use App\Models\Export;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Export\CatalogExporter;
use App\Services\Export\ExportManager;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportReader;
use App\Services\Import\ValueParser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\ImportFiles;

/**
 * @return array<int, list<string|null>> rows of a sheet keyed by source row number
 */
function exportedRows(string $path, string $sheet = 'Товары'): array
{
    return iterator_to_array((new ImportReader)->open($path, $sheet));
}

/**
 * @param  array<string, mixed>  $filters
 * @return array<int, list<string|null>>
 */
function runExport(array $filters = [], string $sheet = 'Товары'): array
{
    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export($filters, $path);
    $rows = exportedRows($path, $sheet);
    unlink($path);

    return $rows;
}

/**
 * @param  list<string|null>  $headers
 * @param  list<string|null>  $row
 * @return array<string, string|null>
 */
function byHeader(array $headers, array $row): array
{
    $result = [];

    foreach ($headers as $i => $header) {
        $result[(string) $header] = $row[$i] ?? null;
    }

    return $result;
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('writes the canonical template headers that map back to the right fields', function (): void {
    Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);

    $rows = runExport();
    $headers = $rows[1];

    expect(array_slice($headers, 0, 20))->toBe(CatalogExporter::productHeaders())
        ->and($headers[0])->toBe('Артикул')
        ->and($headers[2])->toBe('Категория')
        ->and($headers[20])->toBe('Х: Цвет');

    $mapping = app(ColumnMapper::class)->autoMap($headers);

    foreach (ImportField::cases() as $i => $field) {
        expect($mapping[$i])->toBe($field->value);
    }

    expect($mapping[20])->toBe('attr:Цвет');
});

it('formats values like the import template', function (): void {
    $root = Category::factory()->create(['name' => 'Электроника']);
    $mid = Category::factory()->childOf($root)->create(['name' => 'Телефоны']);
    $leaf = Category::factory()->childOf($mid)->create(['name' => 'Смартфоны']);

    $product = Product::factory()->create([
        'category_id' => $leaf->id,
        'sku' => 'EXP-1',
        'name' => 'Смартфон',
        'base_price' => '12990.00',
        'cost_price' => '9500.50',
        'compare_at_price' => '14990.00',
        'stock_qty' => null,
        'unit' => 'шт',
        'brand' => 'Acme',
        'is_active' => true,
        'is_featured' => true,
        'is_new' => false,
        'sales_mode' => SalesMode::Both,
        'price_on_request' => false,
        'sort_order' => 7,
        'meta_title' => 'MT',
        'meta_description' => 'MD',
        'attributes' => [['name' => 'Цвет', 'value' => 'Чёрный'], ['name' => 'Материал', 'value' => 'Сталь']],
    ]);
    ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 0, 'path_large' => 'products/1/a-1600.webp']);
    ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 1, 'path_large' => 'products/1/b-1600.webp']);

    $second = Product::factory()->create([
        'category_id' => $root->id,
        'base_price' => '100.00',
        'cost_price' => null,
        'compare_at_price' => null,
        'stock_qty' => 0,
        'is_active' => false,
        'sales_mode' => null,
        'price_on_request' => true,
        'attributes' => [],
    ]);

    $rows = runExport();
    $headers = $rows[1];
    $first = byHeader($headers, $rows[2]);
    $other = byHeader($headers, $rows[3]);

    expect($first['Артикул'])->toBe('EXP-1')
        ->and($first['Категория'])->toBe('Электроника / Телефоны / Смартфоны')
        ->and($first['Цена'])->toBe('12990')
        ->and($first['Закупочная цена'])->toBe('9500.5')
        ->and($first['Старая цена'])->toBe('14990')
        ->and($first['Остаток'])->toBeNull()
        ->and($first['Активен'])->toBe('да')
        ->and($first['Хит'])->toBe('да')
        ->and($first['Новинка'])->toBe('нет')
        ->and($first['Режим продажи'])->toBe('Корзина и резерв')
        ->and($first['Цена по запросу'])->toBe('нет')
        ->and($first['Порядок'])->toBe('7')
        ->and($first['Фото'])->toBe(
            Storage::disk('public')->url('products/1/a-1600.webp').'; '.Storage::disk('public')->url('products/1/b-1600.webp'),
        )
        ->and($first['Х: Цвет'])->toBe('Чёрный')
        ->and($first['Х: Материал'])->toBe('Сталь');

    expect($other['Активен'])->toBe('нет')
        ->and($other['Остаток'])->toBe('0')
        ->and($other['Режим продажи'])->toBeNull()
        ->and($other['Цена по запросу'])->toBe('да')
        ->and($other['Закупочная цена'])->toBeNull()
        ->and($other['Х: Цвет'])->toBeNull()
        ->and($other['Категория'])->toBe('Электроника');

    // The exported cells re-import to the very same values.
    $parser = new ValueParser;
    expect($parser->price($first['Цена']))->toBe(1_299_000)
        ->and($parser->price($first['Закупочная цена']))->toBe(950_050)
        ->and($parser->bool($first['Активен']))->toBeTrue()
        ->and($parser->bool($other['Активен']))->toBeFalse()
        ->and($parser->salesMode($first['Режим продажи']))->toBe(SalesMode::Both)
        ->and($second->exists)->toBeTrue();
});

it('keeps price cells numeric', function (): void {
    Product::factory()->create(['base_price' => '1290.50']);

    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);

    $reader = new Reader;
    $reader->open($path);
    $cell = null;

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $i => $row) {
            if ($i === 2) {
                $cell = $row->getCellAtIndex(3);
            }
        }
        break;
    }

    $reader->close();
    unlink($path);

    expect($cell)->toBeInstanceOf(NumericCell::class)
        ->and($cell->getValue())->toBe(1290.5);
});

it('exports characteristic columns in a stable sorted order', function (): void {
    Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Белый'], ['name' => 'Вес', 'value' => '2 кг']]]);
    Product::factory()->create(['attributes' => [['name' => 'Бренд', 'value' => 'X'], ['name' => 'Цвет', 'value' => 'Синий']]]);

    $headers = runExport()[1];

    expect(array_slice($headers, 20))->toBe(['Х: Бренд', 'Х: Вес', 'Х: Цвет']);
});

it('filters by category with descendants and by activity', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    $other = Category::factory()->create();

    Product::factory()->create(['category_id' => $root->id, 'sku' => 'IN-ROOT']);
    Product::factory()->create(['category_id' => $child->id, 'sku' => 'IN-CHILD', 'is_active' => false]);
    Product::factory()->create(['category_id' => $other->id, 'sku' => 'OUTSIDE']);

    $skus = fn (array $filters): array => array_map(fn (array $row): ?string => $row[0], array_slice(runExport($filters), 1));

    expect($skus(['category_id' => $root->id]))->toEqualCanonicalizing(['IN-ROOT', 'IN-CHILD'])
        ->and($skus(['category_id' => $child->id]))->toBe(['IN-CHILD'])
        ->and($skus(['category_id' => $root->id, 'active' => 'active']))->toBe(['IN-ROOT'])
        ->and($skus(['active' => 'inactive']))->toBe(['IN-CHILD'])
        ->and($skus([]))->toHaveCount(3)
        ->and($skus(['product_ids' => [Product::query()->where('sku', 'OUTSIDE')->value('id')]]))->toBe(['OUTSIDE']);
});

it('writes the «Категории» sheet with every category', function (): void {
    $root = Category::factory()->create(['name' => 'Мебель', 'sort_order' => 3, 'sales_mode' => SalesMode::Reservation, 'meta_title' => 'MT']);
    Category::factory()->childOf($root)->inactive()->create(['name' => 'Диваны']);

    $rows = runExport([], 'Категории');

    expect($rows[1])->toBe(CatalogExporter::categoryHeaders())
        ->and($rows[2][0])->toBe('Мебель')
        ->and($rows[2][2])->toBe('3')
        ->and($rows[2][3])->toBe('да')
        ->and($rows[2][4])->toBe('Резерв')
        ->and($rows[2][6])->toBe('MT')
        ->and($rows[3][0])->toBe('Мебель / Диваны')
        ->and($rows[3][3])->toBe('нет');

    $mapping = app(ColumnMapper::class)->autoMapCategories($rows[1]);

    expect(array_values($mapping))->toBe(['path', 'description', 'sort', 'active', 'sales_mode', 'photo', 'meta_title', 'meta_description']);
});

it('builds a small export synchronously', function (): void {
    $user = User::factory()->manager()->create();
    Product::factory()->count(3)->create();

    $export = app(ExportManager::class)->request(ExportType::Catalog, ['active' => 'all'], $user);

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->rows)->toBe(3)
        ->and($export->file_path)->toMatch('#^exports/'.$export->id.'/catalog-\d{8}-\d{6}\.xlsx$#');
    Storage::disk('local')->assertExists($export->file_path);
});

it('queues an export above the synchronous limit and the job builds the file and notifies the user', function (): void {
    Queue::fake([ExportCatalog::class]);
    config(['shop.export_sync_limit' => 2]);
    $user = User::factory()->manager()->create();
    Product::factory()->count(3)->create();

    $export = app(ExportManager::class)->request(ExportType::Catalog, [], $user);

    expect($export->status)->toBe(ExportStatus::Queued)->and($export->file_path)->toBeNull();
    Queue::assertPushed(ExportCatalog::class, fn (ExportCatalog $job): bool => $job->exportId === $export->id && $job->timeout === 40);

    (new ExportCatalog($export->id))->handle(app(ExportManager::class));

    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Completed)->and($export->rows)->toBe(3);
    Storage::disk('local')->assertExists($export->file_path);

    $notification = $user->notifications()->sole();
    expect($notification->data['title'])->toContain('3')
        ->and(json_encode($notification->data))->toContain('exports\/'.$export->id.'\/download');
});

it('does not queue at the limit', function (): void {
    Queue::fake([ExportCatalog::class]);
    config(['shop.export_sync_limit' => 3]);
    Product::factory()->count(3)->create();

    $export = app(ExportManager::class)->request(ExportType::Catalog, [], User::factory()->admin()->create());

    expect($export->status)->toBe(ExportStatus::Completed);
    Queue::assertNothingPushed();
});

it('marks a failed export and keeps no file', function (): void {
    config(['shop.export_sync_limit' => 0]);
    Queue::fake([ExportCatalog::class]);
    $user = User::factory()->admin()->create();
    Product::factory()->create();
    $export = app(ExportManager::class)->request(ExportType::Catalog, [], $user);

    $job = new ExportCatalog($export->id);
    $job->failed(new RuntimeException('Boom'));

    expect($export->refresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->failure_message)->toBe('Boom')
        ->and($user->notifications()->count())->toBe(1);
});

it('purges files older than seven days and keeps the journal', function (): void {
    $old = Export::factory()->status(ExportStatus::Completed)->create(['file_path' => 'exports/1/catalog.xlsx']);
    $fresh = Export::factory()->status(ExportStatus::Completed)->create(['file_path' => 'exports/2/catalog.xlsx']);
    Storage::disk('local')->put('exports/'.$old->id.'/catalog.xlsx', 'x');
    Storage::disk('local')->put('exports/'.$fresh->id.'/catalog.xlsx', 'x');
    $old->update(['file_path' => 'exports/'.$old->id.'/catalog.xlsx']);
    $fresh->update(['file_path' => 'exports/'.$fresh->id.'/catalog.xlsx']);
    Export::query()->whereKey($old->id)->update(['created_at' => now()->subDays(8)]);

    expect(app(ExportManager::class)->purgeOlderThan(7))->toBe(1)
        ->and($old->refresh()->file_path)->toBeNull()
        ->and($fresh->refresh()->file_path)->not->toBeNull();
    Storage::disk('local')->assertMissing('exports/'.$old->id.'/catalog.xlsx');
    Storage::disk('local')->assertExists('exports/'.$fresh->id.'/catalog.xlsx');
});

describe('download route', function (): void {
    function finishedExport(User $owner): Export
    {
        $export = Export::factory()->status(ExportStatus::Completed)->create(['user_id' => $owner->id]);
        $path = 'exports/'.$export->id.'/catalog-20260101-000000.xlsx';
        Storage::disk('local')->put($path, 'xlsx-bytes');
        $export->update(['file_path' => $path]);

        return $export;
    }

    it('lets the owner and an administrator download', function (): void {
        $owner = User::factory()->manager()->create();
        $export = finishedExport($owner);

        $this->actingAs($owner)->get(route('admin.exports.download', $export))->assertOk()->assertDownload('catalog-20260101-000000.xlsx');
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.exports.download', $export))->assertOk();
    });

    it('forbids another manager', function (): void {
        $export = finishedExport(User::factory()->manager()->create());

        $this->actingAs(User::factory()->manager()->create())->get(route('admin.exports.download', $export))->assertForbidden();
    });

    it('redirects a guest to the login page', function (): void {
        $export = finishedExport(User::factory()->manager()->create());

        $this->get(route('admin.exports.download', $export))->assertRedirect();
    });

    it('answers 404 when the file is gone', function (): void {
        $owner = User::factory()->manager()->create();
        $export = finishedExport($owner);
        $export->update(['file_path' => null]);

        $this->actingAs($owner)->get(route('admin.exports.download', $export))->assertNotFound();
    });
});
