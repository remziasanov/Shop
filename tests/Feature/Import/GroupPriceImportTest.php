<?php

declare(strict_types=1);

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportField;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\PriceChangeSource;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Import;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportWizard;
use App\Services\Pricing\PriceChangeContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    // The wholesale group «Опт» (code opt) comes from the migration of stage 9.
    $this->group = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $this->vip = CustomerGroup::factory()->create(['code' => 'vip', 'name' => 'VIP']);
});

/**
 * @param  list<list<string|int|float|null>>  $rows
 * @param  array<string, mixed>  $attributes
 */
function runGroupCsv(array $rows, array $attributes = []): Import
{
    return ImportHarness::run(ImportHarness::analyzed(ImportFiles::csv($rows), $attributes));
}

/**
 * @return array<int, string> min_qty => price of the item for the group
 */
function importedTiers(Product|ProductVariant $item, CustomerGroup $group): array
{
    $query = ProductPriceTier::query()->where('customer_group_id', $group->id)->orderBy('min_qty')
        ->where('product_id', $item instanceof ProductVariant ? $item->product_id : $item->id)
        ->where('variant_key', $item instanceof ProductVariant ? $item->id : 0);

    return $query->get()->mapWithKeys(fn (ProductPriceTier $row): array => [$row->min_qty => (string) $row->price])->all();
}

/**
 * @return list<string> messages of the issues of the import
 */
function importIssueMessages(Import $import, string $level = 'error'): array
{
    return $import->issues()->where('level', $level)->pluck('message')->all();
}

describe('columns (GP-15)', function (): void {
    it('recognizes «Цена: <код>» and «Цена: <код> от <N>» case-insensitively', function (): void {
        $mapping = app(ColumnMapper::class)->autoMap(['Артикул', 'Цена', 'Цена: opt', 'цена: OPT от 10', 'Price: Opt from 50', 'PRICE : vip  ОТ 5', 'Цена: opt от 1']);

        expect($mapping)->toBe([0 => 'sku', 1 => 'price', 2 => 'gp:opt', 3 => 'gp:opt:10', 4 => 'gp:opt:50', 5 => 'gp:vip:5', 6 => null]);
    });

    it('keeps the plain price and the other price columns apart', function (): void {
        expect(app(ColumnMapper::class)->autoMap(['Цена', 'Закупка', 'Цена до скидки', 'Розничная цена']))->toBe([0 => 'price', 1 => 'cost_price', 2 => 'old_price', 3 => null]);
    });

    it('builds the mapping values and headers', function (): void {
        expect(ImportField::groupPriceKey('OPT'))->toBe('gp:opt')
            ->and(ImportField::groupPriceKey('opt', 1))->toBe('gp:opt')
            ->and(ImportField::groupPriceKey('opt', 10))->toBe('gp:opt:10')
            ->and(ImportField::groupPriceTarget('gp:opt:10'))->toBe(['code' => 'opt', 'qty' => 10])
            ->and(ImportField::groupPriceTarget('gp:opt'))->toBe(['code' => 'opt', 'qty' => 1])
            ->and(ImportField::groupPriceTarget('attr:gp:x'))->toBeNull()
            ->and(ImportField::groupPriceTarget('gp:'))->toBeNull()
            ->and(ColumnMapper::groupPriceHeader('opt'))->toBe('Цена: opt')
            ->and(ColumnMapper::groupPriceHeader('opt', 10))->toBe('Цена: opt от 10')
            ->and(ColumnMapper::fieldLabel('gp:opt:10'))->toBe('Цена: opt от 10');
    });

    it('offers the group price columns in the mapping select and accepts them', function (): void {
        $wizard = app(ImportWizard::class);
        $options = $wizard->targetOptions(['Артикул', 'Цена: opt от 10'], [0 => 'sku', 1 => 'gp:opt:10']);

        expect($options['gp:opt:10'])->toBe('Цена: opt от 10')
            ->and($wizard->validateMapping([0 => 'sku', 1 => 'gp:opt:10'], ['Артикул', 'Цена: opt от 10']))->toBe([0 => 'sku', 1 => 'gp:opt:10']);
    });

    it('ignores a column of an unknown group with a warning', function (): void {
        Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);

        $import = ImportHarness::analyzed(ImportFiles::csv([
            ['Артикул', 'Цена: opt', 'Цена: nope', 'Цена: nope от 5'],
            ['A-1', '900', '800', '700'],
        ]));

        expect($import->analysis['mapping_warnings'])->toHaveCount(2)
            ->and($import->analysis['mapping_warnings'][0])->toContain('nope')->toContain('Цена: nope')
            ->and($import->mapping)->toBe([0 => 'sku', 1 => 'gp:opt', 2 => null, 3 => null]);

        ImportHarness::run($import);

        expect(ProductPriceTier::query()->count())->toBe(1)
            ->and(importedTiers(Product::query()->where('sku', 'A-1')->firstOrFail(), $this->group))->toBe([1 => '900.00']);
    });
});

describe('product rows (GP-15)', function (): void {
    it('sets the exact price and the tiers of an existing product with history and a recalculation', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);

        $import = runGroupCsv([
            ['Артикул', 'Цена: opt', 'Цена: opt от 10', 'Цена: OPT от 50', 'Цена: vip'],
            ['A-1', '950', '900,50', '850', '700'],
        ]);

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->updated_count)->toBe(1)
            ->and($import->error_count)->toBe(0)
            ->and(importedTiers($product, $this->group))->toBe([1 => '950.00', 10 => '900.50', 50 => '850.00'])
            ->and(importedTiers($product, $this->vip))->toBe([1 => '700.00']);

        $history = PriceHistory::query()->where('product_id', $product->id)->where('customer_group_id', $this->group->id)->orderBy('min_qty')->get();

        expect($history)->toHaveCount(3)
            ->and($history[0]->field)->toBe('group_price')
            ->and($history[1]->field)->toBe('group_tier')
            ->and($history[0]->source)->toBe(PriceChangeSource::Import)
            ->and($history[0]->import_id)->toBe($import->id)
            // One recalculation at the end of the import (touched products): the group price rows exist.
            ->and(gpRow($product, $this->group))->toBe(['final' => '950.00', 'old' => null])
            ->and(gpRow($product, $this->vip))->toBe(['final' => '700.00', 'old' => null]);
    });

    it('creates a new product together with its group prices', function (): void {
        Category::factory()->create(['name' => 'Мебель']);

        $import = runGroupCsv([
            ['Артикул', 'Название', 'Категория', 'Цена', 'Цена: opt', 'Цена: opt от 5'],
            ['N-1', 'Новый стул', 'Мебель', '1000', '900', '800'],
        ]);

        $product = Product::query()->where('sku', 'N-1')->firstOrFail();

        expect($import->created_count)->toBe(1)
            ->and(importedTiers($product, $this->group))->toBe([1 => '900.00', 5 => '800.00']);
    });

    it('keeps a price on a blank cell and clears it under the clear policy', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);
        gpTier($product, $this->group, 1, '950.00');
        gpTier($product, $this->group, 10, '900.00');
        $rows = [['Артикул', 'Цена: opt', 'Цена: opt от 10'], ['A-1', '', '']];

        $kept = runGroupCsv($rows);

        expect($kept->unchanged_count)->toBe(1)
            ->and(importedTiers($product, $this->group))->toBe([1 => '950.00', 10 => '900.00']);

        $cleared = runGroupCsv($rows, ['options' => ['empty_cell_policy' => EmptyCellPolicy::Clear->value]]);

        expect($cleared->updated_count)->toBe(1)
            ->and(importedTiers($product, $this->group))->toBe([]);
    });

    it('deletes a price or a tier with «-»', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);
        gpTier($product, $this->group, 1, '950.00');
        gpTier($product, $this->group, 10, '900.00');

        $import = runGroupCsv([['Артикул', 'Цена: opt', 'Цена: opt от 10'], ['A-1', '-', '800']]);

        expect($import->error_count)->toBe(0)
            ->and(importedTiers($product, $this->group))->toBe([10 => '800.00'])
            ->and(PriceHistory::query()->where('field', 'group_price')->latest('id')->first()->new_value)->toBeNull();
    });

    it('rewrites a tier and leaves the untouched ones alone', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);
        gpTier($product, $this->group, 10, '900.00');
        gpTier($product, $this->group, 50, '800.00');

        runGroupCsv([['Артикул', 'Цена: opt от 10'], ['A-1', '850']]);

        expect(importedTiers($product, $this->group))->toBe([10 => '850.00', 50 => '800.00']);
    });

    it('applies group prices in the prices and stock mode and skips unknown products', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00', 'name' => 'Прежнее имя']);

        $import = runGroupCsv([
            ['Артикул', 'Название', 'Цена: opt'],
            ['A-1', 'Другое имя', '900'],
            ['ZZ-9', 'Новый', '800'],
        ], ['mode' => ImportMode::PricesStock]);

        expect($import->updated_count)->toBe(1)
            ->and($import->skipped_count)->toBe(1)
            ->and(importedTiers($product, $this->group))->toBe([1 => '900.00'])
            ->and($product->refresh()->name)->toBe('Прежнее имя');
    });

    it('is idempotent: the same file again changes nothing and writes no history', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);
        $rows = [['Артикул', 'Цена: opt', 'Цена: opt от 10'], ['A-1', '950', '900']];

        runGroupCsv($rows);
        $history = PriceHistory::query()->count();
        $second = runGroupCsv($rows);

        expect($second->unchanged_count)->toBe(1)
            ->and($second->updated_count)->toBe(0)
            ->and(PriceHistory::query()->count())->toBe($history);
    });
});

describe('validation (GP-14)', function (): void {
    it('reports a price that grows with the quantity inside the row as a row error', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00', 'name' => 'Прежнее']);

        $import = runGroupCsv([['Артикул', 'Название', 'Цена: opt от 10', 'Цена: opt от 50'], ['A-1', 'Новое', '800', '900']]);

        expect($import->error_count)->toBe(1)
            ->and($import->status)->toBe(ImportStatus::CompletedWithErrors)
            ->and(importedTiers($product, $this->group))->toBe([])
            ->and($product->refresh()->name)->toBe('Прежнее');

        $issue = $import->issues()->where('level', 'error')->firstOrFail();

        expect($issue->column)->toBe('Цена: opt от 50')->and($issue->message)->toContain('дороже ступени');
    });

    it('reports a conflict with the stored tiers and leaves the whole row untouched', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00', 'name' => 'Прежнее']);
        gpTier($product, $this->group, 20, '900.00');

        $import = runGroupCsv([['Артикул', 'Название', 'Цена: opt от 10'], ['A-1', 'Новое', '800']]);

        expect($import->error_count)->toBe(1)
            ->and(importedTiers($product, $this->group))->toBe([20 => '900.00'])
            ->and($product->refresh()->name)->toBe('Прежнее')
            ->and(importIssueMessages($import)[0])->toContain('Опт');
    });

    it('rejects zero and unparsable prices', function (string $cell): void {
        Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);

        $import = runGroupCsv([['Артикул', 'Цена: opt'], ['A-1', $cell]]);

        expect($import->error_count)->toBe(1)->and(ProductPriceTier::query()->count())->toBe(0);
    })->with(['0', 'abc', '-5']);

    it('warns about a tier above the retail price without blocking', function (): void {
        $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);

        $import = runGroupCsv([['Артикул', 'Цена: opt от 10'], ['A-1', '1200']]);

        expect($import->error_count)->toBe(0)
            ->and($import->warning_count)->toBeGreaterThan(0)
            ->and(importedTiers($product, $this->group))->toBe([10 => '1200.00'])
            ->and(importIssueMessages($import, 'warning')[0])->toContain('выше розничной');
    });

    it('refuses group prices on the row of a card with variants', function (): void {
        $card = variantCard(['sku' => 'CARD', 'base_price' => '1000.00'], optionWith('Размер', ['S']));

        $import = runGroupCsv([['Артикул', 'Цена: opt'], ['CARD', '900']]);

        expect($import->error_count)->toBe(1)
            ->and(importIssueMessages($import)[0])->toContain('модификац')
            ->and(ProductPriceTier::query()->count())->toBe(0)
            ->and($card->refresh()->has_variants)->toBeTrue();
    });

    it('does not touch a card with variants on blank cells under the clear policy', function (): void {
        variantCard(['sku' => 'CARD', 'base_price' => '1000.00'], optionWith('Размер', ['S']));

        $import = runGroupCsv([['Артикул', 'Цена: opt'], ['CARD', '']], ['options' => ['empty_cell_policy' => EmptyCellPolicy::Clear->value]]);

        expect($import->error_count)->toBe(0);
    });
});

describe('variant rows (GP-15)', function (): void {
    it('applies the prices of a variant row to the variant', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard(['sku' => 'TEE', 'base_price' => '1000.00'], $size);
        $variant = variantOf($card, [$size->values[0]], ['sku' => 'TEE-S']);

        $import = runGroupCsv([
            ['Артикул', 'Артикул карточки', 'Опция: Размер', 'Цена: opt', 'Цена: opt от 5'],
            ['TEE-S', 'TEE', 'S', '900', '850'],
            ['TEE-M', 'TEE', 'M', '910', ''],
        ]);

        $created = ProductVariant::query()->where('sku', 'TEE-M')->firstOrFail();

        expect($import->error_count)->toBe(0)
            ->and(importedTiers($variant, $this->group))->toBe([1 => '900.00', 5 => '850.00'])
            ->and(importedTiers($created, $this->group))->toBe([1 => '910.00'])
            ->and(importedTiers($card, $this->group))->toBe([])
            ->and(PriceHistory::query()->where('variant_id', $variant->id)->count())->toBe(2)
            ->and(gpRow($variant, $this->group))->toBe(['final' => '900.00', 'old' => null]);
    });

    it('updates variant prices in the prices and stock mode and counts the row as updated', function (): void {
        $size = optionWith('Размер', ['S']);
        $card = variantCard(['sku' => 'TEE', 'base_price' => '1000.00'], $size);
        $variant = variantOf($card, [$size->values[0]], ['sku' => 'TEE-S']);

        $import = runGroupCsv([['Артикул', 'Артикул карточки', 'Цена: opt'], ['TEE-S', 'TEE', '900']], ['mode' => ImportMode::PricesStock]);

        expect($import->updated_count)->toBe(1)->and(importedTiers($variant, $this->group))->toBe([1 => '900.00']);
    });

    it('reports a variant tier conflict as a row error', function (): void {
        $size = optionWith('Размер', ['S']);
        $card = variantCard(['sku' => 'TEE', 'base_price' => '1000.00'], $size);
        $variant = variantOf($card, [$size->values[0]], ['sku' => 'TEE-S']);

        $import = runGroupCsv([['Артикул', 'Артикул карточки', 'Цена: opt от 5', 'Цена: opt от 10'], ['TEE-S', 'TEE', '800', '900']]);

        expect($import->error_count)->toBe(1)->and(importedTiers($variant, $this->group))->toBe([]);
    });
});

it('skips the synchronous group recalculation in the import context and still ends with correct group rows', function (): void {
    $this->vip->update(['default_discount_percent' => '10']);

    $inContext = app(PriceChangeContext::class)->run(PriceChangeSource::Import, null, fn () => Product::factory()->create(['base_price' => '1000.00']));

    expect(gpRow($inContext, $this->vip))->toBeNull();

    $import = runGroupCsv([
        ['Артикул', 'Название', 'Категория', 'Цена'],
        ['G-1', 'Стол для группы', 'Мебель', '2000'],
    ]);

    $created = Product::query()->where('sku', 'G-1')->firstOrFail();

    expect($import->error_count)->toBe(0)->and(gpRow($created, $this->vip)['final'])->toBe('1800.00');
});
