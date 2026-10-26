<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Import\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHostResolver;
use Tests\Support\ImportHarness;

// Stage 3 acceptance: the demo file with its photo archive imports; importing it again creates nothing and changes nothing.

/**
 * @return array{products: array<int, array<string, mixed>>, images: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
 */
function demoSnapshot(): array
{
    return [
        'products' => Product::withTrashed()->orderBy('id')->get()->map(fn (Product $p): array => $p->toArray())->all(),
        'images' => ProductImage::query()->orderBy('id')->get()->toArray(),
        'categories' => Category::query()->orderBy('id')->get()->toArray(),
    ];
}

function runDemoImport(): Import
{
    $import = ImportHarness::analyzed(base_path('examples/import-demo.xlsx'));
    ImportHarness::attachZip($import, base_path('examples/import-demo-photos.zip'));

    return ImportHarness::run($import->refresh());
}

it('imports the demo file with its photo archive and a second import changes nothing', function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    app()->instance(HostResolver::class, new FakeHostResolver);
    Http::fake();

    $first = runDemoImport();

    expect($first->status)->toBe(ImportStatus::Completed)
        ->and($first->error_count)->toBe(0)
        ->and($first->created_count)->toBe(50)
        ->and(Product::count())->toBe(50)
        ->and(Category::count())->toBe(15)
        ->and(ProductImage::count())->toBeGreaterThanOrEqual(60);

    $before = demoSnapshot();

    $second = runDemoImport();

    expect($second->status)->toBe(ImportStatus::Completed)
        ->and($second->error_count)->toBe(0)
        ->and($second->created_count)->toBe(0)
        ->and($second->updated_count)->toBe(0)
        ->and($second->unchanged_count)->toBe(50)
        ->and(demoSnapshot())->toEqual($before);

    Http::assertNothingSent();
});
