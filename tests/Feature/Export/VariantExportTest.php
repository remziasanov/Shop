<?php

declare(strict_types=1);

use App\Enums\ImportField;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\OptionValue;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Export\CatalogExporter;
use App\Services\Import\ImportReader;
use App\Services\Media\ImageProcessor;
use App\Services\Variants\VariantService;
use Illuminate\Support\Facades\DB;
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
 * @param  array<string, mixed>  $filters
 * @return array<int, list<string|null>> rows of the «Товары» sheet keyed by source row number
 */
function variantExportRows(array $filters = []): array
{
    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export($filters, $path);
    $rows = iterator_to_array((new ImportReader)->open($path, 'Товары'));
    unlink($path);

    return $rows;
}

/**
 * @param  list<string|null>  $headers
 * @param  list<string|null>  $row
 * @return array<string, string|null>
 */
function variantByHeader(array $headers, array $row): array
{
    $result = [];

    foreach ($headers as $i => $header) {
        $result[(string) $header] = $row[$i] ?? null;
    }

    return $result;
}

/**
 * @return array<string, array<string, mixed>>
 */
function variantProductSnapshot(): array
{
    return Product::withTrashed()->orderBy('id')->get()
        ->mapWithKeys(fn (Product $p): array => [$p->sku => $p->only([
            'id', 'category_id', 'name', 'slug', 'base_price', 'cost_price', 'compare_at_price', 'final_price', 'final_price_max',
            'stock_qty', 'reserved_qty', 'has_variants', 'is_active', 'sort_order', 'updated_at', 'deleted_at',
        ])])
        ->all();
}

/**
 * @return array<int, array<string, mixed>>
 */
function variantImageSnapshot(): array
{
    return ProductImage::query()->orderBy('id')->get()
        ->mapWithKeys(fn (ProductImage $i): array => [$i->id => $i->only(['product_id', 'path_large', 'alt', 'sort_order', 'source_hash', 'option_value_id'])])
        ->all();
}

/**
 * A T-shirt card with Цвет × Размер and three variants, and a plain product.
 *
 * @return array{card: Product, plain: Product, variants: list<ProductVariant>}
 */
function variantCatalog(): array
{
    $category = Category::factory()->create(['name' => 'Одежда']);
    $color = optionWith('Цвет', ['Красный', 'Синий'], color: true);
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard(['sku' => 'TEE', 'name' => 'Футболка', 'base_price' => '1000.00', 'category_id' => $category->id], $color, $size);

    $variants = [
        variantOf($card, [$color->values[0], $size->values[0]], ['sku' => 'TEE-R-S', 'barcode' => '4600000000001', 'stock_qty' => 5, 'base_price' => '1000.00', 'sort_order' => 1]),
        variantOf($card, [$color->values[0], $size->values[1]], ['sku' => 'TEE-R-M', 'stock_qty' => 3, 'base_price' => '1100.00', 'sort_order' => 2]),
        variantOf($card, [$color->values[1], $size->values[0]], ['sku' => 'TEE-B-S', 'stock_qty' => null, 'base_price' => null, 'is_active' => false, 'sort_order' => 3]),
    ];
    $plain = Product::factory()->inCategory($category)->create(['sku' => 'PLAIN', 'base_price' => '50.00', 'stock_qty' => 2]);

    return ['card' => $card->refresh(), 'plain' => $plain, 'variants' => $variants];
}

it('writes the card row followed by its variant rows with the option columns (VR-18)', function (): void {
    variantCatalog();

    $rows = variantExportRows();
    $headers = $rows[1];
    $named = array_map(fn (array $row): array => variantByHeader($headers, $row), array_values(array_slice($rows, 1)));

    expect($headers)->toContain('Артикул карточки')->and($headers)->toContain('Штрихкод')->and($headers)->toContain('Опция: Цвет')->and($headers)->toContain('Опция: Размер')
        ->and(array_slice($headers, -2))->toBe(['Опция: Цвет', 'Опция: Размер'])
        ->and(array_column($named, 'Артикул'))->toBe(['TEE', 'TEE-R-S', 'TEE-R-M', 'TEE-B-S', 'PLAIN']);

    [$card, $first, , $third, $plain] = $named;

    expect($card['Артикул карточки'])->toBeNull()
        ->and((float) $card['Цена'])->toBe(1000.0)
        // The card's stock is the aggregate of its active variants.
        ->and((int) $card['Остаток'])->toBe(8)
        ->and($first['Артикул карточки'])->toBe('TEE')
        ->and($first['Название'])->toBeNull()
        ->and($first['Категория'])->toBeNull()
        ->and($first['Штрихкод'])->toBe('4600000000001')
        ->and($first['Опция: Цвет'])->toBe('Красный')
        ->and($first['Опция: Размер'])->toBe('S')
        ->and((int) $first['Остаток'])->toBe(5)
        ->and($first['Активен'])->toBe('да')
        ->and($third['Цена'])->toBeNull()
        ->and($third['Остаток'])->toBeNull()
        ->and($third['Активен'])->toBe('нет')
        ->and($plain['Артикул карточки'])->toBeNull()
        ->and($plain['Опция: Цвет'])->toBeNull();
});

it('adds no option columns when no card has variants', function (): void {
    Product::factory()->create();

    $headers = variantExportRows()[1];

    expect(collect($headers)->filter(fn (?string $header): bool => str_starts_with((string) $header, 'Опция: ')))->toBeEmpty()
        ->and(array_slice($headers, 0, count(ImportField::cases())))->toBe(CatalogExporter::productHeaders());
});

it('exports the variants of the selected cards only and skips deleted variants', function (): void {
    $catalog = variantCatalog();
    app(VariantService::class)->delete($catalog['variants'][2]);

    $rows = variantExportRows(['product_ids' => [$catalog['card']->id]]);

    expect(array_column(array_map(fn (array $row): array => variantByHeader($rows[1], $row), array_values(array_slice($rows, 1))), 'Артикул'))->toBe(['TEE', 'TEE-R-S', 'TEE-R-M']);

    $without = variantExportRows(['product_ids' => [$catalog['plain']->id]]);

    expect(collect($without[1])->filter(fn (?string $header): bool => str_starts_with((string) $header, 'Опция: ')))->toBeEmpty()
        ->and(count($without))->toBe(2);
});

it('lists the photos of a colour on its first variant row and the common photos on the card row', function (): void {
    $catalog = variantCatalog();
    $processor = app(ImageProcessor::class);
    $common = $processor->attachToProduct($catalog['card'], ImportFiles::tempJpeg(100, 100, 10));
    $red = $processor->attachToProduct($catalog['card'], ImportFiles::tempJpeg(100, 100, 120));
    $blue = $processor->attachToProduct($catalog['card'], ImportFiles::tempJpeg(100, 100, 230));
    $red->update(['option_value_id' => OptionValue::query()->where('value', 'Красный')->value('id')]);
    $blue->update(['option_value_id' => OptionValue::query()->where('value', 'Синий')->value('id')]);

    $rows = variantExportRows();
    $named = array_map(fn (array $row): array => variantByHeader($rows[1], $row), array_values(array_slice($rows, 1)));
    $bySku = array_combine(array_column($named, 'Артикул'), $named);

    expect($bySku['TEE']['Фото'])->toBe($common->urlLarge())
        ->and($bySku['TEE-R-S']['Фото'])->toBe($red->urlLarge())
        ->and($bySku['TEE-R-M']['Фото'])->toBeNull()
        ->and($bySku['TEE-B-S']['Фото'])->toBe($blue->urlLarge());
});

/**
 * Everything a variant carries that the import writes.
 *
 * @return array<string, array<string, mixed>>
 */
function variantSnapshot(): array
{
    $snapshot = [];

    foreach (ProductVariant::withTrashed()->orderBy('id')->get() as $variant) {
        $snapshot[$variant->sku] = $variant->only([
            'id', 'product_id', 'barcode', 'combo_hash', 'base_price', 'cost_price', 'compare_at_price', 'fixed_price',
            'final_price', 'display_old_price', 'stock_qty', 'reserved_qty', 'is_active', 'sort_order', 'updated_at', 'deleted_at',
        ]) + ['values' => $variant->values()->orderBy('option_type_id')->get()->map->only(['option_type_id', 'option_value_id'])->all()];
    }

    return $snapshot;
}

it('imports its own export without changing anything (VR-18, round trip)', function (): void {
    Http::fake();
    $catalog = variantCatalog();
    $processor = app(ImageProcessor::class);
    $processor->attachToProduct($catalog['card'], ImportFiles::tempJpeg(100, 100, 10));
    $red = $processor->attachToProduct($catalog['card'], ImportFiles::tempJpeg(100, 100, 120));
    $blue = $processor->attachToProduct($catalog['card'], ImportFiles::tempJpeg(100, 100, 230));
    $red->update(['option_value_id' => OptionValue::query()->where('value', 'Красный')->value('id')]);
    $blue->update(['option_value_id' => OptionValue::query()->where('value', 'Синий')->value('id')]);

    $this->travel(5)->minutes();
    $productsBefore = variantProductSnapshot();
    $variantsBefore = variantSnapshot();
    $imagesBefore = variantImageSnapshot();
    $optionsBefore = OptionValue::query()->orderBy('id')->get(['id', 'option_type_id', 'value', 'slug', 'sort_order'])->toArray();
    $historyBefore = PriceHistory::query()->count();

    foreach ([1, 2] as $_) {
        $path = ImportFiles::tempPath('xlsx');
        app(CatalogExporter::class)->export([], $path);

        $import = ImportHarness::run(ImportHarness::analyzed($path));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->error_count)->toBe(0)
            ->and($import->warning_count)->toBe(0)
            ->and($import->created_count)->toBe(0)
            ->and($import->updated_count)->toBe(0)
            ->and($import->unchanged_count)->toBe(5);
    }

    Http::assertNothingSent();

    expect(variantProductSnapshot())->toEqual($productsBefore)
        ->and(variantSnapshot())->toEqual($variantsBefore)
        ->and(variantImageSnapshot())->toEqual($imagesBefore)
        ->and(OptionValue::query()->orderBy('id')->get(['id', 'option_type_id', 'value', 'slug', 'sort_order'])->toArray())->toEqual($optionsBefore)
        ->and(PriceHistory::query()->count())->toBe($historyBefore)
        ->and(ProductImage::query()->whereNotNull('option_value_id')->count())->toBe(2);
});

it('rebuilds the same catalog from its export in an empty database (VR-18)', function (): void {
    $catalog = variantCatalog();
    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);

    $expected = ProductVariant::query()->orderBy('id')->get()->map(fn (ProductVariant $v): array => [
        $v->sku, $v->barcode, $v->base_price, $v->stock_qty, $v->is_active, $v->sort_order,
        $v->values()->orderBy('option_type_id')->get()->map(fn ($value): string => OptionValue::query()->find($value->option_value_id)->value)->all(),
    ])->all();
    $cardExpected = $catalog['card']->only(['final_price', 'final_price_max', 'stock_qty']);

    // Wipe the catalog but keep the file.
    DB::table('cart_items')->delete();
    DB::table('product_variant_values')->delete();
    ProductVariant::withTrashed()->forceDelete();
    DB::table('product_options')->delete();
    Product::withTrashed()->forceDelete();
    DB::table('option_values')->delete();
    DB::table('option_types')->delete();

    $import = ImportHarness::run(ImportHarness::analyzed($path));

    expect($import->status)->toBe(ImportStatus::Completed)->and($import->created_count)->toBe(5);

    $actual = ProductVariant::query()->orderBy('id')->get()->map(fn (ProductVariant $v): array => [
        $v->sku, $v->barcode, $v->base_price, $v->stock_qty, $v->is_active, $v->sort_order,
        $v->values()->orderBy('option_type_id')->get()->map(fn ($value): string => OptionValue::query()->find($value->option_value_id)->value)->all(),
    ])->all();

    expect($actual)->toEqual($expected)
        ->and(Product::query()->where('sku', 'TEE')->firstOrFail()->only(['final_price', 'final_price_max', 'stock_qty']))->toEqual($cardExpected)
        ->and(Product::query()->where('sku', 'TEE')->value('has_variants'))->toBeTrue();
});
