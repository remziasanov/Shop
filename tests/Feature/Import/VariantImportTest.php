<?php

declare(strict_types=1);

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportField;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\OptionDisplayType;
use App\Enums\OrderStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\Import;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Export\VariantsExampleBuilder;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportReader;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportWizard;
use App\Services\Variants\VariantService;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
});

const VARIANT_HEADER = ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Остаток', 'Штрихкод', 'Активен', 'Опция: Цвет', 'Опция: Размер'];

/**
 * @param  list<list<string|int|float|null>>  $rows
 * @param  array<string, mixed>  $attributes  attributes of the import (mode, options)
 */
function runVariantCsv(array $rows, array $attributes = []): Import
{
    return ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv($rows), $attributes));
}

/**
 * @param  list<list<string|int|float|null>>  $rows
 * @param  array<string, mixed>  $attributes
 */
function analyzeVariantCsv(array $rows, array $attributes = []): Import
{
    return ImportHarness::analyzed(ImportFiles::csv($rows), $attributes);
}

/**
 * @return array<int, list<string>> row number => error messages of the staged rows
 */
function variantStagedErrors(Import $import): array
{
    $errors = [];

    foreach (file(Storage::disk('local')->path($import->staging_path), FILE_IGNORE_NEW_LINES) as $line) {
        $row = json_decode($line, true);

        if ($row['errors'] !== []) {
            $errors[$row['row']] = array_map(fn (array $e): string => $e['message'], $row['errors']);
        }
    }

    return $errors;
}

function variantTShirtRows(): array
{
    return [
        VARIANT_HEADER,
        ['TEE', '', 'Футболка «Север»', 'Одежда / Футболки', '1000', '', '', 'да', '', ''],
        ['TEE-R-S', 'TEE', '', '', '1000', '5', '4600000000001', 'да', 'Красный', 'S'],
        ['TEE-R-M', 'TEE', '', '', '1100', '3', '4600000000002', 'да', 'Красный', 'M'],
        ['TEE-B-S', 'TEE', '', '', '', '', '', 'нет', 'Синий', 'S'],
    ];
}

describe('column mapping (VR-16)', function (): void {
    it('recognizes the card article, the barcode and the option columns by their headers and synonyms', function (): void {
        $mapping = app(ColumnMapper::class)->autoMap(['Артикул', 'Родительский артикул', 'parent sku', 'Штрихкод', 'Опция: Цвет', 'Option: Размер', 'Х: Материал']);

        expect($mapping)->toBe([
            0 => 'sku',
            1 => 'parent_sku',
            2 => null,
            3 => 'barcode',
            4 => 'opt:Цвет',
            5 => 'opt:Размер',
            6 => 'attr:Материал',
        ]);

        expect(app(ColumnMapper::class)->autoMap(['Parent SKU'])[0])->toBe('parent_sku')
            ->and(ImportField::ParentSku->label())->toBe('Артикул карточки');
    });

    it('offers the option columns in the mapping select', function (): void {
        $options = app(ImportWizard::class)->targetOptions(['Артикул', 'Опция: Цвет'], [0 => 'sku', 1 => 'opt:Цвет']);

        expect($options)->toHaveKey('opt:Цвет')->and($options)->toHaveKey('parent_sku')->and($options)->toHaveKey('barcode');
    });
});

describe('card and variants (VR-16, VR-17)', function (): void {
    it('creates the card, its options and values and the variants in file order', function (): void {
        $import = runVariantCsv(variantTShirtRows());

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->error_count)->toBe(0)
            ->and($import->created_count)->toBe(4);

        $card = Product::query()->where('sku', 'TEE')->firstOrFail();
        $color = OptionType::query()->where('name', 'Цвет')->firstOrFail();
        $size = OptionType::query()->where('name', 'Размер')->firstOrFail();

        expect($card->has_variants)->toBeTrue()
            ->and($card->name)->toBe('Футболка «Север»')
            ->and(app(VariantService::class)->axes($card)->pluck('name')->all())->toBe(['Цвет', 'Размер'])
            ->and($color->display_type)->toBe(OptionDisplayType::Color)
            ->and($size->display_type)->toBe(OptionDisplayType::Select)
            ->and($color->values()->pluck('value')->all())->toBe(['Красный', 'Синий'])
            ->and($size->values()->pluck('value')->all())->toBe(['S', 'M']);

        $variants = ProductVariant::query()->where('product_id', $card->id)->orderBy('id')->get();
        $first = $variants[0];

        expect($variants->pluck('sku')->all())->toBe(['TEE-R-S', 'TEE-R-M', 'TEE-B-S'])
            ->and($first->base_price)->toBe('1000.00')
            ->and($first->stock_qty)->toBe(5)
            ->and($first->barcode)->toBe('4600000000001')
            ->and($variants[1]->base_price)->toBe('1100.00')
            ->and($variants[2]->base_price)->toBeNull()
            ->and($variants[2]->stock_qty)->toBeNull()
            ->and($variants[2]->is_active)->toBeFalse()
            ->and($variants[2]->final_price)->toBe('1000.00');

        // The card's aggregates follow the active variants.
        $card->refresh();
        expect($card->final_price)->toBe('1000.00')->and($card->final_price_max)->toBe('1100.00')->and($card->stock_qty)->toBe(8);
    });

    it('imports the same file a second time without changing anything', function (): void {
        runVariantCsv(variantTShirtRows());
        $before = ProductVariant::query()->orderBy('id')->get()->map->only(['id', 'sku', 'barcode', 'base_price', 'stock_qty', 'is_active', 'sort_order', 'combo_hash', 'updated_at'])->all();
        $card = Product::query()->where('sku', 'TEE')->firstOrFail()->only(['final_price', 'final_price_max', 'stock_qty', 'updated_at']);

        $this->travel(5)->minutes();
        $second = runVariantCsv(variantTShirtRows());

        expect($second->status)->toBe(ImportStatus::Completed)
            ->and($second->created_count)->toBe(0)
            ->and($second->updated_count)->toBe(0)
            ->and($second->unchanged_count)->toBe(4)
            ->and(ProductVariant::query()->orderBy('id')->get()->map->only(['id', 'sku', 'barcode', 'base_price', 'stock_qty', 'is_active', 'sort_order', 'combo_hash', 'updated_at'])->all())->toEqual($before)
            ->and(Product::query()->where('sku', 'TEE')->firstOrFail()->only(['final_price', 'final_price_max', 'stock_qty', 'updated_at']))->toEqual($card)
            ->and(OptionValue::query()->count())->toBe(4);
    });

    it('updates an existing variant: price, stock, barcode, values (VR-16)', function (): void {
        runVariantCsv(variantTShirtRows());

        $rows = variantTShirtRows();
        $rows[2] = ['TEE-R-S', 'TEE', '', '', '1234,50', '9', '4600000000099', 'да', 'Красный', 'L'];
        $import = runVariantCsv($rows);

        $variant = ProductVariant::query()->where('sku', 'TEE-R-S')->firstOrFail();

        expect($import->updated_count)->toBe(1)
            ->and($variant->base_price)->toBe('1234.50')
            ->and($variant->stock_qty)->toBe(9)
            ->and($variant->barcode)->toBe('4600000000099')
            ->and($variant->values()->get()->map(fn ($v) => OptionValue::query()->find($v->option_value_id)->value)->sort()->values()->all())->toBe(['L', 'Красный'])
            ->and(ProductVariant::query()->count())->toBe(3);
    });

    it('inherits an empty name and category and never changes the card by its variant rows', function (): void {
        $category = Category::factory()->create(['name' => 'Одежда']);
        $card = Product::factory()->inCategory($category)->create(['sku' => 'OLD', 'name' => 'Старая куртка', 'base_price' => '500.00']);

        $import = runVariantCsv([
            VARIANT_HEADER,
            ['OLD-S', 'OLD', 'Чужое название', 'Чужая / Категория', '600', '1', '', 'да', '', 'S'],
        ]);

        expect($import->status)->toBe(ImportStatus::Completed);

        $card->refresh();
        expect($card->name)->toBe('Старая куртка')->and($card->category_id)->toBe($category->id)->and($card->base_price)->toBe('500.00')
            ->and($card->has_variants)->toBeTrue()
            ->and(Category::query()->where('name', 'Чужая')->exists())->toBeFalse();
    });

    it('attaches variants to a card that exists in the catalog and has variants already', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard(['sku' => 'CARD'], $size);
        variantOf($card, [$size->values[0]], ['sku' => 'CARD-S']);

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Цена', 'Опция: Размер'],
            ['CARD-M', 'CARD', '700', 'M'],
        ]);

        expect($import->created_count)->toBe(1)
            ->and(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(2)
            ->and(OptionValue::query()->where('option_type_id', $size->id)->count())->toBe(2);
    });

    it('restores a deleted variant when its row comes again', function (): void {
        runVariantCsv(variantTShirtRows());
        $variant = ProductVariant::query()->where('sku', 'TEE-B-S')->firstOrFail();
        app(VariantService::class)->delete($variant);

        $import = runVariantCsv(variantTShirtRows());

        expect($import->updated_count)->toBe(1)->and(ProductVariant::query()->where('sku', 'TEE-B-S')->exists())->toBeTrue();
    });

    it('applies the empty-cell policy to the nullable fields of a variant', function (): void {
        runVariantCsv(variantTShirtRows());

        $rows = variantTShirtRows();
        $rows[2] = ['TEE-R-S', 'TEE', '', '', '', '', '', '', 'Красный', 'S'];
        runVariantCsv($rows);
        $kept = ProductVariant::query()->where('sku', 'TEE-R-S')->firstOrFail();
        expect($kept->base_price)->toBe('1000.00')->and($kept->stock_qty)->toBe(5)->and($kept->barcode)->toBe('4600000000001');

        runVariantCsv($rows, ['options' => ['empty_cell_policy' => EmptyCellPolicy::Clear->value]]);
        $cleared = $kept->refresh();
        expect($cleared->base_price)->toBeNull()->and($cleared->stock_qty)->toBeNull()->and($cleared->barcode)->toBeNull();
    });
});

describe('chunks', function (): void {
    it('applies the variants of a card in later chunks than the card and keeps the aggregates right', function (): void {
        $rows = [['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Остаток', 'Опция: Размер']];

        for ($card = 1; $card <= 3; $card++) {
            $rows[] = ['C'.$card, '', 'Карточка '.$card, 'Раздел', '100', '', ''];

            foreach ([1, 2, 3, 4] as $size) {
                $rows[] = ['C'.$card.'-'.$size, 'C'.$card, '', '', (string) (100 * $size), (string) $size, 'Р'.$size];
            }
        }

        $import = runVariantCsv($rows, ['chunk_size' => 3]);

        expect($import->chunks_total)->toBe(5)
            ->and($import->status)->toBe(ImportStatus::Completed)
            ->and($import->created_count)->toBe(15)
            ->and(ProductVariant::query()->count())->toBe(12);

        foreach (Product::query()->get() as $card) {
            expect($card->has_variants)->toBeTrue()
                ->and($card->final_price)->toBe('100.00')
                ->and($card->final_price_max)->toBe('400.00')
                ->and($card->stock_qty)->toBe(10);
        }

        expect(OptionValue::query()->count())->toBe(4);
    });

    it('reports a dry run without writing a variant', function (): void {
        $import = runVariantCsv(variantTShirtRows(), ['dry_run' => true]);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->created_count)->toBe(4)
            ->and(ProductVariant::query()->count())->toBe(0)
            ->and(OptionType::query()->count())->toBe(0)
            ->and(Product::query()->count())->toBe(0);
    });
});

describe('analyzer checks (VR-17)', function (): void {
    it('reports a variant whose card is missing or comes below it', function (): void {
        $import = analyzeVariantCsv([
            VARIANT_HEADER,
            ['LATE-S', 'LATE', '', '', '10', '', '', '', '', 'S'],
            ['LATE', '', 'Товар', 'Раздел', '100', '', '', '', '', ''],
            ['NONE-S', 'NONE', '', '', '10', '', '', '', '', 'S'],
        ]);

        $errors = variantStagedErrors($import);

        expect($errors)->toHaveKeys([2, 4])->and($errors)->not->toHaveKey(3)
            ->and($errors[2][0])->toBe(__('admin_variants.import.card_missing', ['sku' => 'LATE']))
            ->and($import->analysis['summary']['errors'])->toBe(2);
    });

    it('does not load the variants of a card that has errors', function (): void {
        $import = analyzeVariantCsv([
            VARIANT_HEADER,
            ['BAD', '', 'Плохой', 'Раздел', 'abc', '', '', '', '', ''],
            ['BAD-S', 'BAD', '', '', '10', '', '', '', '', 'S'],
        ]);

        expect(variantStagedErrors($import)[3][0])->toBe(__('admin_variants.import.card_invalid', ['sku' => 'BAD', 'row' => 2]));
    });

    it('takes the axes from the first variant and refuses a different set afterwards', function (): void {
        $import = analyzeVariantCsv([
            VARIANT_HEADER,
            ['TEE', '', 'Футболка', 'Одежда', '1000', '', '', '', '', ''],
            ['TEE-1', 'TEE', '', '', '', '', '', '', 'Красный', 'S'],
            ['TEE-2', 'TEE', '', '', '', '', '', '', 'Красный', ''],
            ['TEE-3', 'TEE', '', '', '', '', '', '', '', 'M'],
            ['TEE-4', 'TEE', '', '', '', '', '', '', 'Синий', 'M'],
        ]);

        $errors = variantStagedErrors($import);

        expect($errors)->toHaveKeys([4, 5])->and($errors)->not->toHaveKeys([3, 6])
            ->and($errors[4][0])->toBe(__('admin_variants.import.option_blank', ['name' => 'Размер']))
            ->and($errors[5][0])->toBe(__('admin_variants.import.option_blank', ['name' => 'Цвет']));
    });

    it('checks the axes of a card that already has variants against the database', function (): void {
        $size = optionWith('Размер', ['S']);
        variantCard(['sku' => 'CARD'], $size);

        $import = analyzeVariantCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Цвет', 'Опция: Размер'],
            ['CARD-1', 'CARD', 'Красный', 'S'],
            ['CARD-2', 'CARD', '', 'S'],
        ]);

        expect(variantStagedErrors($import))->toHaveKey(2)->and(variantStagedErrors($import))->not->toHaveKey(3)
            ->and(variantStagedErrors($import)[2][0])->toBe(__('admin_variants.import.option_not_axis', ['name' => 'Цвет', 'axes' => 'Размер']));
    });

    it('refuses a repeated combination and a missing option', function (): void {
        $import = analyzeVariantCsv([
            VARIANT_HEADER,
            ['TEE', '', 'Футболка', 'Одежда', '1000', '', '', '', '', ''],
            ['TEE-1', 'TEE', '', '', '', '', '', '', 'Красный', 'S'],
            ['TEE-2', 'TEE', '', '', '', '', '', '', ' красный ', 's'],
            ['TEE-3', 'TEE', '', '', '', '', '', '', '', ''],
        ]);

        $errors = variantStagedErrors($import);

        expect($errors[4][0])->toBe(__('admin_variants.import.combo_duplicate', ['row' => 3]))
            ->and($errors[5][0])->toBe(__('admin_variants.import.options_missing'));
    });

    it('refuses an article that belongs to the other kind of entity', function (): void {
        $plain = Product::factory()->create(['sku' => 'PLAIN']);
        $size = optionWith('Размер', ['S']);
        $card = variantCard(['sku' => 'CARD'], $size);
        variantOf($card, [$size->values[0]], ['sku' => 'CARD-S']);

        $import = analyzeVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Опция: Размер'],
            ['PLAIN', 'CARD', '', '', '10', 'S'],
            ['CARD-S', '', 'Название', 'Раздел', '10', ''],
        ]);

        $errors = variantStagedErrors($import);

        expect($errors[2][0])->toBe(__('admin_variants.import.sku_is_card', ['sku' => 'PLAIN']))
            ->and($errors[3][0])->toBe(__('admin_variants.import.sku_is_variant', ['sku' => 'CARD-S']));

        $run = ImportHarness::run($import);

        expect($run->status)->toBe(ImportStatus::CompletedWithErrors)->and($run->error_count)->toBe(2)
            ->and(Product::query()->where('sku', 'CARD-S')->exists())->toBeFalse();

        $viaVariant = analyzeVariantCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Размер'],
            ['NEW-S', 'CARD-S', 'S'],
        ]);

        expect(variantStagedErrors($viaVariant)[2][0])->toBe(__('admin_variants.import.parent_is_variant', ['sku' => 'CARD-S']));
    });

    it('refuses a variant article of another card', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $one = variantCard(['sku' => 'ONE'], $size);
        variantCard(['sku' => 'TWO'], $size);
        variantOf($one, [$size->values[0]], ['sku' => 'ONE-S']);

        $import = analyzeVariantCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Размер'],
            ['ONE-S', 'TWO', 'M'],
        ]);

        expect(variantStagedErrors($import)[2][0])->toBe(__('admin_variants.import.variant_other_card', ['sku' => 'ONE-S']));
    });

    it('reports a variant of a trashed card', function (): void {
        $card = Product::factory()->create(['sku' => 'GONE']);
        $card->delete();

        $import = analyzeVariantCsv([['Артикул', 'Артикул карточки', 'Опция: Размер'], ['GONE-S', 'GONE', 'S']]);

        expect(variantStagedErrors($import)[2][0])->toBe(__('admin_variants.import.card_trashed', ['sku' => 'GONE']));
    });

    it('shows the options of variant rows in the preview', function (): void {
        $import = analyzeVariantCsv(variantTShirtRows());

        expect($import->analysis['preview'][1]['options'])->toBe([['name' => 'Цвет', 'value' => 'Красный'], ['name' => 'Размер', 'value' => 'S']])
            ->and($import->analysis['summary']['create'])->toBe(4);
    });
});

describe('options and limits', function (): void {
    it('refuses unknown options and values when creating them on import is off', function (): void {
        app(CatalogSettings::class)->fill(['create_options_on_import' => false])->save();
        $color = optionWith('Цвет', ['Красный'], color: true);

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Опция: Цвет'],
            ['CARD', '', 'Товар', 'Раздел', '100', ''],
            ['CARD-R', 'CARD', '', '', '', 'Красный'],
            ['CARD-G', 'CARD', '', '', '', 'Зелёный'],
        ]);

        expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
            ->and($import->error_count)->toBe(1)
            ->and(ProductVariant::query()->pluck('sku')->all())->toBe(['CARD-R'])
            ->and(OptionValue::query()->where('option_type_id', $color->id)->count())->toBe(1)
            ->and($import->issues()->first()->message)->toBe(__('admin_variants.import.value_unknown', ['option' => 'Цвет', 'value' => 'Зелёный']));

        $unknown = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Материал'],
            ['CARD-M', 'CARD', 'Хлопок'],
        ]);

        expect($unknown->error_count)->toBe(1)->and(OptionType::query()->where('name', 'Материал')->exists())->toBeFalse();
    });

    it('finds options and values regardless of case, spaces and ё', function (): void {
        optionWith('Цвет', ['Зелёный'], color: true);

        runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Опция: цвет'],
            ['CARD', '', 'Товар', 'Раздел', '100', ''],
            ['CARD-G', 'CARD', '', '', '', ' зеленый '],
        ]);

        expect(OptionType::query()->count())->toBe(1)->and(OptionValue::query()->count())->toBe(1)->and(ProductVariant::query()->count())->toBe(1);
    });

    it('stops at the limit of variants per card with a row error (VR-02)', function (): void {
        app(CatalogSettings::class)->fill(['max_variants_per_product' => 2])->save();

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Опция: Размер'],
            ['CARD', '', 'Товар', 'Раздел', '100', ''],
            ['CARD-1', 'CARD', '', '', '', '1'],
            ['CARD-2', 'CARD', '', '', '', '2'],
            ['CARD-3', 'CARD', '', '', '', '3'],
        ]);

        expect($import->error_count)->toBe(1)->and(ProductVariant::query()->count())->toBe(2)
            ->and($import->issues()->first()->message)->toBe(__('variants.errors.limit', ['max' => 2, 'total' => 3]));
    });

    it('refuses a variant article that a product already uses (SkuGuard)', function (): void {
        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Опция: Размер'],
            ['CARD', '', 'Товар', 'Раздел', '100', ''],
            ['CARD-1', 'CARD', '', '', '', '1'],
        ]);
        expect($import->error_count)->toBe(0);

        // Another import creates the product «CARD-2» after the variant article «CARD-2» was taken by a race: the guard refuses.
        Product::factory()->create(['sku' => 'CARD-2']);
        $second = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Размер'],
            ['CARD-2', 'CARD', '2'],
        ]);

        expect($second->error_count)->toBe(1);
    });
});

describe('enabling variants on an existing product (VR-25)', function (): void {
    it('turns a plain product into a card with variants through its first variant row', function (): void {
        $product = Product::factory()->create(['sku' => 'PLAIN', 'base_price' => '500.00', 'stock_qty' => 4]);

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Цена', 'Остаток', 'Опция: Размер'],
            ['PLAIN-S', 'PLAIN', '', '2', 'S'],
        ]);

        expect($import->error_count)->toBe(0)
            ->and($product->refresh()->has_variants)->toBeTrue()
            ->and($product->stock_qty)->toBe(2);
    });

    it('refuses with the numbers of the unfinished requests', function (): void {
        $product = Product::factory()->create(['sku' => 'PLAIN']);
        $order = Order::factory()->order()->create(['status' => OrderStatus::New]);
        OrderItem::factory()->forProduct($product, 1)->create(['order_id' => $order->id]);

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Размер'],
            ['PLAIN-S', 'PLAIN', 'S'],
        ]);

        expect($import->error_count)->toBe(1)
            ->and($product->refresh()->has_variants)->toBeFalse()
            ->and($import->issues()->first()->message)->toBe(__('variants.errors.unfinished_requests', ['numbers' => $order->number]));
    });

    it('ignores the stock cell of a card row once it has variants', function (): void {
        runVariantCsv(variantTShirtRows());
        $rows = variantTShirtRows();
        $rows[1][5] = '99';

        $import = runVariantCsv($rows);

        expect($import->error_count)->toBe(0)->and(Product::query()->where('sku', 'TEE')->value('stock_qty'))->toBe(8);
    });
});

describe('photos of variant rows (VR-17, VR-11)', function (): void {
    function variantPhotoImport(array $rows, array $files): Import
    {
        $import = analyzeVariantCsv($rows, ['options' => ['photo_mode' => 'replace']]);
        ImportHarness::attachZip($import, ImportHarness::zip($files));

        return ImportHarness::run($import);
    }

    it('binds the photos to the colour value of the variant', function (): void {
        $import = variantPhotoImport([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Фото', 'Опция: Цвет', 'Опция: Размер'],
            ['TEE', '', 'Футболка', 'Одежда', '1000', 'common.jpg', '', ''],
            ['TEE-R-S', 'TEE', '', '', '', 'red.jpg', 'Красный', 'S'],
            ['TEE-R-M', 'TEE', '', '', '', 'red.jpg', 'Красный', 'M'],
            ['TEE-B-S', 'TEE', '', '', '', 'blue.jpg', 'Синий', 'S'],
        ], [
            'common.jpg' => ImportHarness::jpeg(120, 80, 10),
            'red.jpg' => ImportHarness::jpeg(120, 80, 200),
            'blue.jpg' => ImportHarness::jpeg(120, 80, 90),
        ]);

        $card = Product::query()->where('sku', 'TEE')->firstOrFail();
        $red = OptionValue::query()->where('value', 'Красный')->firstOrFail();
        $blue = OptionValue::query()->where('value', 'Синий')->firstOrFail();
        $images = ProductImage::query()->where('product_id', $card->id)->get();

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($images)->toHaveCount(3)
            ->and($images->where('option_value_id', null))->toHaveCount(1)
            ->and($images->where('option_value_id', $red->id))->toHaveCount(1)
            ->and($images->where('option_value_id', $blue->id))->toHaveCount(1);
    });

    it('replaces only the photos of the same colour on a second run', function (): void {
        $rows = [
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Фото', 'Опция: Цвет'],
            ['TEE', '', 'Футболка', 'Одежда', '1000', 'common.jpg', ''],
            ['TEE-R', 'TEE', '', '', '', 'red.jpg', 'Красный'],
            ['TEE-B', 'TEE', '', '', '', 'blue.jpg', 'Синий'],
        ];
        $files = ['common.jpg' => ImportHarness::jpeg(120, 80, 10), 'red.jpg' => ImportHarness::jpeg(120, 80, 200), 'blue.jpg' => ImportHarness::jpeg(120, 80, 90)];

        variantPhotoImport($rows, $files);
        $before = ProductImage::query()->orderBy('id')->pluck('id')->all();

        $rows[2][5] = 'red2.jpg';
        $files['red2.jpg'] = ImportHarness::jpeg(120, 80, 250);
        variantPhotoImport($rows, $files);

        $card = Product::query()->where('sku', 'TEE')->firstOrFail();
        $red = OptionValue::query()->where('value', 'Красный')->firstOrFail();
        $after = ProductImage::query()->where('product_id', $card->id)->get();

        // common and blue are the very same images, the red one was replaced.
        expect($after)->toHaveCount(3)
            ->and($after->pluck('id')->intersect($before)->count())->toBe(2)
            ->and($after->where('option_value_id', $red->id)->pluck('id')->intersect($before)->count())->toBe(0);
    });

    it('warns and skips the photos of a variant when the card has no colour axis', function (): void {
        $import = variantPhotoImport([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Фото', 'Опция: Размер'],
            ['TEE', '', 'Футболка', 'Одежда', '1000', '', ''],
            ['TEE-S', 'TEE', '', '', '', 'red.jpg', 'S'],
        ], ['red.jpg' => ImportHarness::jpeg()]);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->warning_count)->toBe(1)
            ->and(ProductImage::query()->count())->toBe(0)
            ->and($import->issues()->first()->message)->toBe(__('admin_variants.import.photo_no_color', ['sku' => 'TEE-S']));
    });
});

describe('import modes (IM-04, VR-17)', function (): void {
    it('«prices and stock» updates the variants and skips the new ones', function (): void {
        runVariantCsv(variantTShirtRows());

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Цена', 'Остаток', 'Штрихкод', 'Опция: Цвет', 'Опция: Размер'],
            ['TEE-R-S', 'TEE', 'Ignored', '1500', '20', '999', 'Красный', 'S'],
            ['TEE-NEW', 'TEE', '', '1500', '20', '', 'Красный', 'XL'],
            ['UNKNOWN-S', 'UNKNOWN', '', '1500', '20', '', 'Красный', 'S'],
        ], ['mode' => ImportMode::PricesStock->value]);

        $variant = ProductVariant::query()->where('sku', 'TEE-R-S')->firstOrFail();

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->updated_count)->toBe(1)
            ->and($import->skipped_count)->toBe(2)
            ->and($import->error_count)->toBe(0)
            ->and($variant->base_price)->toBe('1500.00')
            ->and($variant->stock_qty)->toBe(20)
            // Barcode and options are not part of this mode.
            ->and($variant->barcode)->toBe('4600000000001')
            ->and(ProductVariant::query()->count())->toBe(3)
            ->and(OptionValue::query()->where('value', 'XL')->exists())->toBeFalse()
            ->and(Product::query()->where('sku', 'TEE')->value('name'))->toBe('Футболка «Север»');
    });

    it('full synchronization deactivates the variants missing from the file and the cards missing with their variants', function (): void {
        runVariantCsv(variantTShirtRows());
        ProductVariant::query()->where('sku', 'TEE-B-S')->firstOrFail()->update(['is_active' => true]);
        $other = optionWith('Размер', ['S']);
        $gone = variantCard(['sku' => 'GONE'], $other);
        $goneVariant = variantOf($gone, [$other->values[0]], ['sku' => 'GONE-S']);
        $plain = Product::factory()->create(['sku' => 'PLAIN']);

        // The file names the card TEE with two of its three variants and the plain product.
        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Название', 'Категория', 'Цена', 'Опция: Цвет', 'Опция: Размер'],
            ['TEE', '', 'Футболка «Север»', 'Одежда / Футболки', '1000', '', ''],
            ['TEE-R-S', 'TEE', '', '', '1000', 'Красный', 'S'],
            ['TEE-R-M', 'TEE', '', '', '1100', 'Красный', 'M'],
            ['PLAIN', '', 'Товар', 'Раздел', '10', '', ''],
        ], ['mode' => ImportMode::FullSync->value]);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->analysis['result']['deactivated'])->toBe(1)
            ->and($import->analysis['result']['deactivated_variants'])->toBe(2)
            ->and(ProductVariant::query()->where('sku', 'TEE-B-S')->value('is_active'))->toBeFalse()
            ->and(ProductVariant::query()->where('sku', 'TEE-R-S')->value('is_active'))->toBeTrue()
            ->and($gone->refresh()->is_active)->toBeFalse()
            ->and($goneVariant->refresh()->is_active)->toBeFalse()
            ->and(Product::query()->where('sku', 'TEE')->value('is_active'))->toBeTrue()
            ->and($plain->refresh()->is_active)->toBeTrue();
    });

    it('full synchronization keeps a card that the file names only through its variants', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard(['sku' => 'CARD'], $size);
        variantOf($card, [$size->values[0]], ['sku' => 'CARD-S']);
        variantOf($card, [$size->values[1]], ['sku' => 'CARD-M']);

        $import = runVariantCsv([
            ['Артикул', 'Артикул карточки', 'Цена', 'Опция: Размер'],
            ['CARD-S', 'CARD', '100', 'S'],
        ], ['mode' => ImportMode::FullSync->value]);

        expect($import->analysis['result']['deactivated'])->toBe(0)
            ->and($card->refresh()->is_active)->toBeTrue()
            ->and(ProductVariant::query()->where('sku', 'CARD-M')->value('is_active'))->toBeFalse()
            ->and(ProductVariant::query()->where('sku', 'CARD-S')->value('is_active'))->toBeTrue();
    });

    it('reports how many cards a full synchronization would deactivate', function (): void {
        $size = optionWith('Размер', ['S']);
        variantCard(['sku' => 'CARD'], $size);
        Product::factory()->create(['sku' => 'OTHER']);
        $import = analyzeVariantCsv([
            ['Артикул', 'Артикул карточки', 'Цена', 'Опция: Размер'],
            ['CARD-S', 'CARD', '100', 'S'],
        ], ['mode' => ImportMode::FullSync->value]);

        expect(app(ImportRunner::class)->fullSyncAffectedCount($import))->toBe(1);
    });
});

describe('example file examples/import-variants.xlsx', function (): void {
    it('is the file the generator writes', function (): void {
        $path = ImportFiles::tempPath('xlsx');
        app(VariantsExampleBuilder::class)->build($path);
        $reader = new ImportReader;

        expect(iterator_to_array($reader->open(base_path('examples/import-variants.xlsx'), 'Товары')))->toEqual(iterator_to_array($reader->open($path, 'Товары')));
    });

    it('imports without errors: a T-shirt with Цвет × Размер and a plain product, then again without changes', function (): void {
        $path = base_path('examples/import-variants.xlsx');
        $import = ImportHarness::run(ImportHarness::analyzed($path));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->error_count)->toBe(0)
            ->and($import->warning_count)->toBe(0)
            ->and($import->created_count)->toBe(8);

        $card = Product::query()->where('sku', 'TSH-100')->firstOrFail();

        expect($card->has_variants)->toBeTrue()
            ->and(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(6)
            ->and(app(VariantService::class)->axes($card)->pluck('name')->all())->toBe(['Цвет', 'Размер'])
            ->and($card->final_price)->toBe('1290.00')->and($card->final_price_max)->toBe('1390.00')
            ->and(Product::query()->where('sku', 'MUG-200')->value('has_variants'))->toBeFalse();

        $second = ImportHarness::run(ImportHarness::analyzed($path));

        expect($second->created_count)->toBe(0)->and($second->updated_count)->toBe(0)->and($second->unchanged_count)->toBe(8);
    });
});
