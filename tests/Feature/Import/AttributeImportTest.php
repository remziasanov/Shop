<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Export\CatalogExporter;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    attrMigrated();
});

const ATTR_IMPORT_HEAD = ['Артикул', 'Название', 'Категория', 'Цена'];

/**
 * @param  list<list<string|int|float|null>>  $rows
 * @param  array<string, mixed>  $attributes
 */
function runAttributeImport(array $rows, array $attributes = []): Import
{
    return ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv($rows), $attributes));
}

/**
 * @return array<string, list<string>> attribute name => value texts of the product, in dictionary order
 */
function productValueTexts(Product $product): array
{
    $texts = [];

    foreach (ProductAttributeValue::query()->where('product_id', $product->id)->with(['attribute', 'value'])->get() as $row) {
        $texts[$row->attribute->name][] = $row->value?->value ?? (string) ($row->value_number ?? ($row->value_bool ? 'yes' : 'no'));
    }

    return $texts;
}

it('creates missing characteristics from «Х:» columns: not filterable, type and unit by the values (FL-16)', function (): void {
    $import = runAttributeImport([
        [...ATTR_IMPORT_HEAD, 'Х: Цвет', 'Х: Мощность, Вт', 'Х: Влагозащита'],
        ['A-1', 'Дрель', 'Инструмент', '100', 'Красный', '900', 'да'],
        ['A-2', 'Шуруповёрт', 'Инструмент', '100', 'Синий', '500 Вт', 'нет'],
    ]);

    expect($import->status)->toBe(ImportStatus::Completed)->and($import->error_count)->toBe(0);

    $color = Attribute::query()->where('name', 'Цвет')->firstOrFail();
    $power = Attribute::query()->where('name', 'Мощность')->firstOrFail();
    $water = Attribute::query()->where('name', 'Влагозащита')->firstOrFail();

    expect($color->type)->toBe(AttributeType::List)
        ->and($power->type)->toBe(AttributeType::Number)->and($power->unit)->toBe('Вт')
        ->and($water->type)->toBe(AttributeType::Boolean)
        ->and(Attribute::query()->where('is_filterable', true)->count())->toBe(0)
        ->and(Attribute::query()->count())->toBe(3);

    $drill = Product::where('sku', 'A-1')->firstOrFail();

    expect(productValueTexts($drill))->toBe(['Цвет' => ['Красный'], 'Мощность' => ['900.0000'], 'Влагозащита' => ['yes']])
        ->and($drill->specs())->toBe([
            ['name' => 'Цвет', 'value' => 'Красный'],
            ['name' => 'Мощность', 'value' => '900 Вт'],
            ['name' => 'Влагозащита', 'value' => 'да'],
        ]);
});

it('finds an existing characteristic by its signature, then by the full name, and only then parses the unit (rules 1-3)', function (): void {
    $signature = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
    // v1 name with the unit inside: «Х: Размер, см» must keep resolving to it (rule 2).
    $v1 = Attribute::factory()->create(['name' => 'Размер, см']);

    runAttributeImport([
        [...ATTR_IMPORT_HEAD, 'Х: Мощность, Вт', 'Х: Размер, см', 'Х: Вес, кг', 'Х: Материал, декор'],
        ['A-1', 'Дрель', 'Инструмент', '100', '900', '10x20', '2,5', 'дуб'],
        ['A-2', 'Пила', 'Инструмент', '100', '700', '30x40', '3', 'ясень'],
    ]);

    expect(Attribute::query()->where('name', 'Мощность')->count())->toBe(1)
        ->and(Attribute::query()->where('name', 'Размер')->exists())->toBeFalse()
        ->and(Attribute::query()->where('name', 'Размер, см')->count())->toBe(1);

    $weight = Attribute::query()->where('name', 'Вес')->firstOrFail();
    $material = Attribute::query()->where('name', 'Материал, декор')->firstOrFail();

    expect($weight->type)->toBe(AttributeType::Number)->and($weight->unit)->toBe('кг')
        ->and($material->type)->toBe(AttributeType::List)->and($material->unit)->toBeNull()
        ->and(Attribute::query()->count())->toBe(4);

    $drill = Product::where('sku', 'A-1')->firstOrFail();

    expect(attrValuesOf($drill, $signature))->toHaveCount(1)
        ->and(attrValuesOf($drill, $v1))->toHaveCount(1);
});

it('splits list cells on «;» and keeps commas inside a value', function (): void {
    runAttributeImport([
        [...ATTR_IMPORT_HEAD, 'Х: Состав'],
        ['A-1', 'Футболка', 'Одежда', '100', 'хлопок; лён'],
        ['A-2', 'Сумка', 'Одежда', '100', 'Стекло, алюминий'],
    ]);

    $composition = Attribute::query()->where('name', 'Состав')->firstOrFail();

    expect(productValueTexts(Product::where('sku', 'A-1')->firstOrFail()))->toBe(['Состав' => ['хлопок', 'лён']])
        ->and(productValueTexts(Product::where('sku', 'A-2')->firstOrFail()))->toBe(['Состав' => ['Стекло, алюминий']])
        ->and($composition->values()->count())->toBe(3);
});

it('does not create characteristics when the setting is off and warns once', function (): void {
    $settings = app(CatalogSettings::class);
    $settings->create_attributes_on_import = false;
    $settings->save();

    $known = Attribute::factory()->create(['name' => 'Цвет']);

    $import = runAttributeImport([
        [...ATTR_IMPORT_HEAD, 'Х: Цвет', 'Х: Новая'],
        ['A-1', 'Дрель', 'Инструмент', '100', 'Красный', 'x'],
        ['A-2', 'Пила', 'Инструмент', '100', 'Синий', 'y'],
    ]);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_count)->toBe(2)
        ->and($import->error_count)->toBe(0)
        ->and($import->warning_count)->toBe(1)
        ->and(Attribute::query()->where('name', 'Новая')->exists())->toBeFalse()
        ->and(attrValuesOf(Product::where('sku', 'A-2')->firstOrFail(), $known))->toHaveCount(1);
});

it('reports a value that does not fit the type of an existing characteristic as a row error', function (): void {
    Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);

    $import = runAttributeImport([
        [...ATTR_IMPORT_HEAD, 'Х: Мощность, Вт'],
        ['A-1', 'Дрель', 'Инструмент', '100', 'много'],
        ['A-2', 'Пила', 'Инструмент', '100', '700'],
    ]);

    expect($import->created_count)->toBe(1)
        ->and($import->error_count)->toBe(1)
        ->and(Product::where('sku', 'A-1')->exists())->toBeFalse()
        ->and(Product::where('sku', 'A-2')->exists())->toBeTrue();
});

it('keeps characteristics of other columns and drops a blank cell only with the clear policy', function (): void {
    $color = Attribute::factory()->create(['name' => 'Цвет']);
    $size = Attribute::factory()->create(['name' => 'Размер']);
    $material = Attribute::factory()->create(['name' => 'Материал']);
    $product = Product::factory()->create(['sku' => 'A-1']);
    attrService()->setProductValues($product, [
        ['attribute_id' => $color->id, 'values' => ['Белый']],
        ['attribute_id' => $size->id, 'values' => ['M']],
        ['attribute_id' => $material->id, 'values' => ['Дерево']],
    ]);

    runAttributeImport([['Артикул', 'Х: Цвет', 'Х: Размер'], ['A-1', 'Чёрный', '']]);
    expect(productValueTexts($product))->toBe(['Цвет' => ['Чёрный'], 'Размер' => ['M'], 'Материал' => ['Дерево']]);

    runAttributeImport([['Артикул', 'Х: Цвет', 'Х: Размер'], ['A-1', 'Чёрный', '']], ['options' => ['empty_cell_policy' => 'clear']]);
    expect(productValueTexts($product))->toBe(['Цвет' => ['Чёрный'], 'Материал' => ['Дерево']]);
});

it('does not write the dictionary in a dry run', function (): void {
    runAttributeImport([
        [...ATTR_IMPORT_HEAD, 'Х: Цвет'],
        ['A-1', 'Дрель', 'Инструмент', '100', 'Красный'],
    ], ['dry_run' => true]);

    expect(Attribute::query()->count())->toBe(0)
        ->and(ProductAttributeValue::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0);
});

it('keeps v1 JSON of a not migrated product instead of replacing it (transition)', function (): void {
    $settings = app(CatalogSettings::class);
    $settings->attributes_migrated_at = null;
    $settings->save();

    $legacy = Product::factory()->create(['sku' => 'A-1', 'attributes' => [['name' => 'Материал', 'value' => 'Дерево'], ['name' => 'Цвет', 'value' => 'Белый']]]);

    runAttributeImport([['Артикул', 'Х: Цвет'], ['A-1', 'Чёрный']]);

    expect($legacy->fresh()->specs())->toBe([['name' => 'Материал', 'value' => 'Дерево'], ['name' => 'Цвет', 'value' => 'Чёрный']])
        ->and(ProductAttributeValue::query()->where('product_id', $legacy->id)->exists())->toBeFalse();
});

it('exports headers as signatures and round-trips without duplicates or changes (FL-16)', function (): void {
    $category = Category::factory()->create(['name' => 'Инструмент']);
    $color = Attribute::factory()->create(['name' => 'Цвет']);
    $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
    $wet = Attribute::factory()->boolean()->create(['name' => 'Влагозащита']);
    $v1 = Attribute::factory()->create(['name' => 'Размер, см']);

    $one = Product::factory()->inCategory($category)->create(['sku' => 'RT-1']);
    $two = Product::factory()->inCategory($category)->create(['sku' => 'RT-2']);
    attrService()->setProductValues($one, [
        ['attribute_id' => $color->id, 'values' => ['Красный', 'Синий']],
        ['attribute_id' => $power->id, 'values' => '900.5'],
        ['attribute_id' => $wet->id, 'values' => true],
        ['attribute_id' => $v1->id, 'values' => ['10x20']],
    ]);
    attrService()->setProductValues($two, [
        ['attribute_id' => $power->id, 'values' => '700'],
        ['attribute_id' => $wet->id, 'values' => false],
    ]);

    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export([], $path);

    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($cell) => $cell, $row->toArray());
        }

        break;
    }

    $reader->close();

    $headers = $rows[0];
    $first = array_combine($headers, array_pad($rows[1], count($headers), null));

    expect(array_slice($headers, 21))->toBe(['Х: Влагозащита', 'Х: Мощность, Вт', 'Х: Размер, см', 'Х: Цвет'])
        ->and($first['Х: Цвет'])->toBe('Красный; Синий')
        ->and($first['Х: Мощность, Вт'])->toBe(900.5)
        ->and($first['Х: Влагозащита'])->toBe('да')
        ->and($first['Х: Размер, см'])->toBe('10x20');

    $before = ProductAttributeValue::query()->orderBy('id')->get(['product_id', 'attribute_id', 'attribute_value_id', 'value_number', 'value_bool'])->toArray();

    $import = ImportHarness::run(ImportHarness::analyzed($path));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->error_count)->toBe(0)
        ->and($import->updated_count)->toBe(0)
        ->and($import->unchanged_count)->toBe(2)
        ->and(Attribute::query()->count())->toBe(4)
        ->and(app(AttributeValue::class)->newQuery()->count())->toBe(3);

    $after = ProductAttributeValue::query()->get(['product_id', 'attribute_id', 'attribute_value_id', 'value_number', 'value_bool'])
        ->sortBy(['product_id', 'attribute_id', 'attribute_value_id'])->values()->toArray();
    $sortedBefore = collect($before)->sortBy(['product_id', 'attribute_id', 'attribute_value_id'])->values()->toArray();

    expect($after)->toEqual($sortedBefore);
});

it('exports the JSON of products that were not migrated and merges names into the dictionary signature', function (): void {
    $settings = app(CatalogSettings::class);
    $settings->attributes_migrated_at = null;
    $settings->save();

    Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
    Product::factory()->create(['sku' => 'L-1', 'attributes' => [['name' => 'Мощность', 'value' => '900 Вт'], ['name' => 'Цвет', 'value' => 'Белый']]]);

    $names = app(CatalogExporter::class)->attributeNames([]);

    expect($names)->toBe(['Мощность, Вт', 'Цвет']);
});
