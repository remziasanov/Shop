<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Enums\SalesMode;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Services\Export\CatalogExporter;
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
 * Everything the import file can carry, plus the derived price fields, keyed by SKU.
 *
 * @return array<string, array<string, mixed>>
 */
function catalogSnapshot(): array
{
    $snapshot = [];

    foreach (Product::withTrashed()->orderBy('id')->get() as $product) {
        // Order matters: an import keeps the existing order of characteristics.
        $attributes = collect($product->attributes ?? [])->map(fn (array $a): array => ['name' => $a['name'], 'value' => $a['value']])->values()->all();

        $snapshot[$product->sku] = [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'attributes' => $attributes,
            'base_price' => $product->base_price,
            'cost_price' => $product->cost_price,
            'compare_at_price' => $product->compare_at_price,
            'fixed_price' => $product->fixed_price,
            'final_price' => $product->final_price,
            'display_old_price' => $product->display_old_price,
            'price_on_request' => $product->price_on_request,
            'stock_qty' => $product->stock_qty,
            'unit' => $product->unit,
            'sales_mode' => $product->sales_mode?->value,
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            'is_new' => $product->is_new,
            'sort_order' => $product->sort_order,
            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'deleted' => $product->trashed(),
        ];
    }

    return $snapshot;
}

/**
 * @return array<int, array<string, mixed>>
 */
function categorySnapshot(): array
{
    return Category::query()->orderBy('id')->get(['id', 'parent_id', 'name', 'slug', 'sort_order', 'is_active', 'sales_mode'])
        ->mapWithKeys(fn (Category $c): array => [$c->id => $c->only(['parent_id', 'name', 'slug', 'sort_order', 'is_active']) + ['sales_mode' => $c->sales_mode?->value]])
        ->all();
}

it('imports its own export without changing a single product field', function (): void {
    $electronics = Category::factory()->create(['name' => 'Электроника']);
    $phones = Category::factory()->childOf($electronics)->create(['name' => 'Телефоны']);
    $smartphones = Category::factory()->childOf($phones)->create(['name' => 'Смартфоны']);
    $furniture = Category::factory()->create(['name' => 'Мебель']);
    $tables = Category::factory()->childOf($furniture)->create(['name' => 'Столы']);

    $products = [
        Product::factory()->inCategory($smartphones)->create([
            'sku' => 'RT-001', 'name' => 'Смартфон «Север» 128 ГБ', 'base_price' => '12990.50', 'cost_price' => '9000.05',
            'compare_at_price' => '14990.99', 'stock_qty' => 7, 'brand' => 'Север', 'sort_order' => 5,
            'meta_title' => 'Купить смартфон', 'meta_description' => 'Описание; с «кавычками» и "двойными"',
            'attributes' => [['name' => 'Цвет', 'value' => 'Чёрный'], ['name' => 'Память', 'value' => '128 ГБ']],
        ]),
        Product::factory()->inCategory($phones)->featured()->isNew()->create(['sku' => 'RT-002', 'base_price' => '0.01', 'stock_qty' => null, 'description' => "<p>Строка 1</p>\n<p>Строка 2 &amp; <b>жирный</b></p>"]),
        Product::factory()->inCategory($tables)->salesMode(SalesMode::Reservation)->create(['sku' => 'RT-003', 'base_price' => '1000.00', 'stock_qty' => 0, 'unit' => 'м']),
        Product::factory()->inCategory($tables)->salesMode(SalesMode::Inquiry)->priceOnRequest()->create(['sku' => 'RT-004', 'base_price' => '0.00', 'stock_qty' => 12]),
        Product::factory()->inCategory($furniture)->salesMode(SalesMode::Showcase)->inactive()->create(['sku' => 'RT-005', 'base_price' => '99999999.99', 'stock_qty' => 1, 'attributes' => []]),
        Product::factory()->inCategory($tables)->salesMode(SalesMode::Both)->untracked()->onSale()->create(['sku' => 'RT-006 / спец', 'base_price' => '4500.40']),
        Product::factory()->inCategory($smartphones)->salesMode(SalesMode::Cart)->create(['sku' => '00123', 'base_price' => '10.10', 'stock_qty' => 100, 'brand' => null, 'short_description' => null]),
    ];

    $productsBefore = catalogSnapshot();
    $categoriesBefore = categorySnapshot();
    $historyBefore = PriceHistory::query()->count();

    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);

    $import = ImportHarness::run(ImportHarness::analyzed($path));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->error_count)->toBe(0)
        ->and($import->created_count)->toBe(0)
        ->and($import->updated_count)->toBe(count($products));

    expect(catalogSnapshot())->toEqual($productsBefore)
        ->and(categorySnapshot())->toEqual($categoriesBefore)
        ->and(Product::withTrashed()->count())->toBe(count($products))
        ->and(PriceHistory::query()->count())->toBe($historyBefore);
});

it('keeps the catalog unchanged on a second round trip as well (idempotency)', function (): void {
    $root = Category::factory()->create(['name' => 'Каталог']);
    $leaf = Category::factory()->childOf(Category::factory()->childOf($root)->create(['name' => 'Раздел']))->create(['name' => 'Подраздел']);
    Product::factory()->count(5)->inCategory($leaf)->create();

    $before = catalogSnapshot();

    foreach ([1, 2] as $_) {
        $path = ImportFiles::tempPath('xlsx');
        app(CatalogExporter::class)->export([], $path);
        ImportHarness::run(ImportHarness::analyzed($path));
    }

    expect(catalogSnapshot())->toEqual($before)->and(PriceHistory::query()->count())->toBe(0);
});
