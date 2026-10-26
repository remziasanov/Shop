<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Enums\SalesMode;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Export\CatalogExporter;
use App\Services\Media\ImageProcessor;
use Illuminate\Support\Facades\Http;
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
        ->and($import->updated_count)->toBe(0)
        ->and($import->unchanged_count)->toBe(count($products));

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

/**
 * @return array<int, array<string, mixed>>
 */
function imageSnapshot(): array
{
    return ProductImage::query()->orderBy('id')->get()
        ->mapWithKeys(fn (ProductImage $i): array => [$i->id => $i->only(['product_id', 'path_large', 'path_medium', 'path_small', 'alt', 'sort_order', 'source_hash', 'width', 'height'])])
        ->all();
}

it('does not download, replace or reorder photos on an export → import round trip (EX-01, own storage URLs)', function (): void {
    Http::fake();
    $processor = app(ImageProcessor::class);

    $category = Category::factory()->create(['name' => 'Мебель']);
    $category->update(['image_path' => $processor->processCategoryImage(ImportFiles::tempJpeg(), $category)]);
    $sub = Category::factory()->childOf($category)->create(['name' => 'Столы']);

    $one = Product::factory()->inCategory($sub)->create(['sku' => 'PH-1']);
    $two = Product::factory()->inCategory($category)->create(['sku' => 'PH-2']);

    // One image with a source hash (imported earlier), two uploaded by hand (no hash).
    $processor->attachToProduct($one, ImportFiles::tempJpeg(300, 200, 10), null, sha1('https://old.example/1.jpg'));
    $processor->attachToProduct($one, ImportFiles::tempJpeg(300, 200, 120));
    $processor->attachToProduct($two, ImportFiles::tempJpeg(200, 200, 220));

    $imagesBefore = imageSnapshot();
    $productsBefore = catalogSnapshot();
    $imagePath = $category->fresh()->image_path;
    $categoryImagesBefore = Category::query()->orderBy('id')->pluck('image_path', 'id')->all();

    expect($imagesBefore)->toHaveCount(3);

    foreach ([1, 2] as $_) {
        $path = ImportFiles::tempPath('xlsx');
        app(CatalogExporter::class)->export([], $path);

        $import = ImportHarness::run(ImportHarness::analyzed($path));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->error_count)->toBe(0)
            ->and($import->warning_count)->toBe(0)
            ->and($import->created_count)->toBe(0)
            ->and($import->updated_count)->toBe(0)
            ->and($import->unchanged_count)->toBe(2);
    }

    Http::assertNothingSent();

    expect(imageSnapshot())->toEqual($imagesBefore)
        ->and(catalogSnapshot())->toEqual($productsBefore)
        ->and($category->fresh()->image_path)->toBe($imagePath)
        ->and(Category::query()->orderBy('id')->pluck('image_path', 'id')->all())->toEqual($categoryImagesBefore);
});

it('applies the photo order of the file to own images and keeps them in replace mode', function (): void {
    Http::fake();
    $processor = app(ImageProcessor::class);
    $product = Product::factory()->create(['sku' => 'PH-ORD']);
    $first = $processor->attachToProduct($product, ImportFiles::tempJpeg(100, 100, 10));
    $second = $processor->attachToProduct($product, ImportFiles::tempJpeg(100, 100, 120));
    $third = $processor->attachToProduct($product, ImportFiles::tempJpeg(100, 100, 230));

    // Cell: second, first (the third one is not listed → replaced away), relative and absolute forms.
    $cell = '/storage/'.$second->path_large.'; '.$first->urlMedium();
    $import = ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Фото'],
        ['PH-ORD', $product->name, $product->category->name, '10', $cell],
    ])));

    Http::assertNothingSent();

    expect($import->error_count)->toBe(0)
        ->and($import->warning_count)->toBe(0)
        ->and($product->images()->orderBy('sort_order')->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and(ProductImage::query()->whereKey($third->id)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists($third->path_large))->toBeFalse()
        ->and(Storage::disk('public')->exists($first->path_large))->toBeTrue();
});

it('copies an image of another product locally instead of downloading it', function (): void {
    Http::fake();
    $processor = app(ImageProcessor::class);
    $donor = Product::factory()->create(['sku' => 'PH-DONOR']);
    $source = $processor->attachToProduct($donor, ImportFiles::tempJpeg(160, 120, 40));
    $target = Product::factory()->create(['sku' => 'PH-TARGET']);

    $import = ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Фото'],
        ['PH-TARGET', $target->name, $target->category->name, '10', $source->urlLarge()],
    ])));

    Http::assertNothingSent();

    $copy = $target->images()->firstOrFail();

    expect($import->warning_count)->toBe(0)
        ->and($donor->images()->count())->toBe(1)
        ->and($copy->path_large)->not->toBe($source->path_large)
        ->and($copy->path_large)->toStartWith('products/'.$target->id.'/')
        ->and(Storage::disk('public')->exists($copy->path_large))->toBeTrue()
        ->and(Storage::disk('public')->exists($source->path_large))->toBeTrue();

    // Second run: the copy is recognized by its hash and nothing else is created.
    ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Фото'],
        ['PH-TARGET', $target->name, $target->category->name, '10', $source->urlLarge()],
    ])));

    expect($target->images()->count())->toBe(1)->and($target->images()->value('id'))->toBe($copy->id);
});
