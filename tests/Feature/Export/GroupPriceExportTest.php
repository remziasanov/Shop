<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\CustomerGroup;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Services\Export\CatalogExporter;
use App\Services\Export\ImportTemplateBuilder;
use App\Services\Import\ImportReader;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    $this->group = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $this->vip = CustomerGroup::factory()->create(['code' => 'vip', 'name' => 'VIP', 'sort_order' => 200]);
});

/**
 * @param  array<string, mixed>  $filters
 * @return array{headers: list<string|null>, rows: list<array<string, string|null>>} the «Товары» sheet, rows by header
 */
function groupExportSheet(array $filters = []): array
{
    $path = ImportFiles::tempPath('xlsx');
    app(CatalogExporter::class)->export($filters, $path);
    $all = array_values(iterator_to_array((new ImportReader)->open($path, 'Товары')));
    unlink($path);

    $headers = $all[0];
    $rows = [];

    foreach (array_slice($all, 1) as $row) {
        $rows[] = array_combine(array_map('strval', $headers), array_pad($row, count($headers), null));
    }

    return ['headers' => $headers, 'rows' => $rows];
}

describe('group price columns (GP-15)', function (): void {
    it('adds no columns while no group has prices', function (): void {
        Product::factory()->create(['sku' => 'A-1']);

        $headers = groupExportSheet()['headers'];

        expect(array_filter($headers, fn (?string $h): bool => str_starts_with((string) $h, 'Цена: ')))->toBe([]);
    });

    it('writes an exact price and a tier column per group that has prices', function (): void {
        $a = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);
        $b = Product::factory()->create(['sku' => 'B-1', 'base_price' => '500.00']);
        gpTier($a, $this->group, 1, '950.00');
        gpTier($a, $this->group, 10, '900.50');
        gpTier($b, $this->group, 10, '450.00');
        gpTier($b, $this->group, 50, '400.00');
        gpTier($a, $this->vip, 1, '700.00');

        $sheet = groupExportSheet();
        $columns = array_values(array_filter($sheet['headers'], fn (?string $h): bool => str_starts_with((string) $h, 'Цена: ')));

        expect($columns)->toBe(['Цена: opt', 'Цена: opt от 10', 'Цена: opt от 50', 'Цена: vip']);

        $rowA = collect($sheet['rows'])->firstWhere('Артикул', 'A-1');
        $rowB = collect($sheet['rows'])->firstWhere('Артикул', 'B-1');

        expect((float) $rowA['Цена: opt'])->toBe(950.0)
            ->and((float) $rowA['Цена: opt от 10'])->toBe(900.5)
            ->and($rowA['Цена: opt от 50'])->toBeNull()
            ->and((float) $rowA['Цена: vip'])->toBe(700.0)
            ->and($rowB['Цена: opt'])->toBeNull()
            ->and((float) $rowB['Цена: opt от 50'])->toBe(400.0)
            ->and($rowB['Цена: vip'])->toBeNull();
    });

    it('limits the columns to the exported products', function (): void {
        $a = Product::factory()->create(['sku' => 'A-1']);
        $b = Product::factory()->create(['sku' => 'B-1']);
        gpTier($a, $this->group, 1, '90.00');
        gpTier($b, $this->vip, 1, '80.00');

        $columns = array_values(array_filter(groupExportSheet(['product_ids' => [$b->id]])['headers'], fn (?string $h): bool => str_starts_with((string) $h, 'Цена: ')));

        expect($columns)->toBe(['Цена: vip']);
    });

    it('writes the prices of a variant on its row and none on the card row', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard(['sku' => 'TEE', 'base_price' => '1000.00'], $size);
        $variant = variantOf($card, [$size->values[0]], ['sku' => 'TEE-S']);
        variantOf($card, [$size->values[1]], ['sku' => 'TEE-M']);
        gpTier($variant, $this->group, 1, '900.00');
        gpTier($variant, $this->group, 5, '850.00');

        $rows = collect(groupExportSheet()['rows']);

        expect($rows->firstWhere('Артикул', 'TEE')['Цена: opt'])->toBeNull()
            ->and((float) $rows->firstWhere('Артикул', 'TEE-S')['Цена: opt'])->toBe(900.0)
            ->and((float) $rows->firstWhere('Артикул', 'TEE-S')['Цена: opt от 5'])->toBe(850.0)
            ->and($rows->firstWhere('Артикул', 'TEE-M')['Цена: opt'])->toBeNull();
    });
});

describe('round trip (GP-15)', function (): void {
    it('imports its own export without changing prices, history or counters', function (): void {
        $a = Product::factory()->create(['sku' => 'A-1', 'base_price' => '1000.00']);
        $b = Product::factory()->create(['sku' => 'B-1', 'base_price' => '12990.50']);
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard(['sku' => 'TEE', 'base_price' => '1000.00'], $size);
        $variant = variantOf($card, [$size->values[0]], ['sku' => 'TEE-S']);
        variantOf($card, [$size->values[1]], ['sku' => 'TEE-M']);

        foreach ([[$a, $this->group, 1, '950.00'], [$a, $this->group, 10, '900.50'], [$b, $this->group, 50, '10000.00'], [$a, $this->vip, 1, '700.00'], [$variant, $this->group, 1, '900.00'], [$variant, $this->group, 5, '850.00']] as [$item, $group, $qty, $price]) {
            gpTier($item, $group, $qty, $price);
        }

        $tiers = fn (): array => ProductPriceTier::query()->orderBy('id')->get(['product_id', 'variant_key', 'customer_group_id', 'min_qty', 'price'])->toArray();
        $before = $tiers();
        $history = PriceHistory::query()->count();

        $path = ImportFiles::tempPath('xlsx');
        app(CatalogExporter::class)->export([], $path);
        $import = ImportHarness::run(ImportHarness::analyzed($path));

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and($import->error_count)->toBe(0)
            ->and($import->warning_count)->toBe(0)
            ->and($import->created_count)->toBe(0)
            ->and($import->updated_count)->toBe(0)
            ->and($import->unchanged_count)->toBe(5)
            ->and($tiers())->toEqual($before)
            ->and(PriceHistory::query()->count())->toBe($history);
    });
});

describe('import template (GP-15, appendix A)', function (): void {
    it('describes the group price columns in the help sheet', function (): void {
        $path = ImportFiles::tempPath('xlsx');
        app(ImportTemplateBuilder::class)->build($path);
        $help = iterator_to_array((new ImportReader)->open($path, 'Справка'));
        $names = array_map(fn (array $row): ?string => $row[0] ?? null, $help);

        expect($names)->toContain('Цена: <код группы>');

        $row = collect($help)->first(fn (array $row): bool => ($row[0] ?? null) === 'Цена: <код группы>');

        expect($row[3])->toContain('Цена: opt от 10')->toContain('«-»');
    });
});
