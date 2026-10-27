<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Import\HostResolver;
use App\Services\Import\ImportPhotoProcessor;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportStep;
use App\Services\Media\ImageProcessor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHostResolver;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

use function Pest\Laravel\actingAs;

// Stage 3 tech debt: photo step idempotency, locks, retry accounting, time reserve, browser failure streak.

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    app()->instance(HostResolver::class, new FakeHostResolver);
});

/**
 * @param  list<list<string>>  $rows
 */
function techDebtImport(array $rows, array $attributes = []): Import
{
    return ImportHarness::analyzed(ImportFiles::csv([['Артикул', 'Название', 'Категория', 'Цена', 'Фото'], ...$rows]), $attributes);
}

function finishImport(ImportRunner $runner, Import $import): Import
{
    for ($i = 0; $i < 100 && $runner->processNext($import->refresh(), 30.0); $i++);

    return $import->refresh();
}

function photosPendingFiles(Import $import): array
{
    return array_values(array_filter(Storage::disk('local')->files('imports/'.$import->id), fn (string $f): bool => str_contains($f, 'photos-pending-')));
}

it('attaches an image with the same source hash to a product only once', function (): void {
    $product = Product::factory()->create();
    $file = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($file, ImportHarness::jpeg());
    $processor = app(ImageProcessor::class);

    $first = $processor->attachToProduct($product, $file, null, sha1('https://img.test/a.jpg'));
    $second = $processor->attachToProduct($product->fresh(), $file, null, sha1('https://img.test/a.jpg'));
    $other = $processor->attachToProduct($product->fresh(), $file, null, sha1('https://img.test/b.jpg'));
    @unlink($file);

    expect($second->id)->toBe($first->id)
        ->and($other->id)->not->toBe($first->id)
        ->and($product->images()->count())->toBe(2);
});

it('ends up with one image when the same photo task is processed twice', function (): void {
    Http::fake(['*' => fn () => Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/a.jpg']]);
    $runner = app(ImportRunner::class);
    $runner->start($import);

    expect($runner->step($import->refresh(), 0.0))->toBe(ImportStep::Chunk);

    // The same task arrives twice (a stale re-queue of a file that was still being processed).
    $files = photosPendingFiles($import);
    expect($files)->toHaveCount(1);
    Storage::disk('local')->copy($files[0], 'imports/'.$import->id.'/photos-pending-duplicate.json');

    finishImport($runner, $import);

    expect(ProductImage::query()->count())->toBe(1);
});

it('does not claim pending photo files while the photo lock is held', function (): void {
    Http::fake(['*' => fn () => Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/a.jpg']]);
    $runner = app(ImportRunner::class);
    $runner->start($import);
    $runner->step($import->refresh(), 0.0);

    $lock = Cache::lock('import:'.$import->id.':photos', 30);
    expect($lock->get())->toBeTrue();

    expect($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Waiting)
        ->and(photosPendingFiles($import))->toHaveCount(1)
        ->and(ProductImage::query()->count())->toBe(0);

    $lock->release();

    expect($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Photos)
        ->and(ProductImage::query()->count())->toBe(1);
});

it('counts warnings once when the photo step throws and is retried', function (): void {
    Http::fake([
        'https://img.test/bad*' => Http::response('', 404),
        '*' => fn () => Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $import = techDebtImport([
        ['A-1', 'Стол', 'Мебель', '100', 'https://img.test/bad1.jpg'],
        ['A-2', 'Стул', 'Мебель', '100', 'https://img.test/ok.jpg'],
        ['A-3', 'Лампа', 'Мебель', '100', 'https://img.test/bad3.jpg'],
    ]);
    $runner = app(ImportRunner::class);
    $runner->start($import);
    $runner->step($import->refresh(), 0.0);

    // The second task blows up once (e.g. a database hiccup), after the first one was finished.
    $thrown = false;
    Product::retrieved(function (Product $product) use (&$thrown): void {
        if ($product->sku === 'A-2' && ! $thrown) {
            $thrown = true;

            throw new RuntimeException('boom');
        }
    });

    expect(fn () => $runner->step($import->refresh(), 30.0))->toThrow(RuntimeException::class);

    $import->refresh();
    expect($import->warning_count)->toBe(1);

    $import = finishImport($runner, $import);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->warning_count)->toBe(2)
        ->and($import->issues()->where('level', 'warning')->count())->toBe(2)
        ->and(ProductImage::query()->count())->toBe(1);
});

it('keeps a time reserve before attaching an image', function (): void {
    Http::fake(['*' => fn () => Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/a.jpg']]);
    $runner = app(ImportRunner::class);
    $runner->start($import);

    // The data step runs; the photo step has less time left than the reserve: nothing is attached, the task waits.
    $runner->step($import->refresh(), 0.0);
    expect($runner->step($import->refresh(), 2.0))->toBe(ImportStep::Waiting)
        ->and(ProductImage::query()->count())->toBe(0)
        ->and(photosPendingFiles($import))->toHaveCount(1);

    expect($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Photos)
        ->and(ProductImage::query()->count())->toBe(1)
        ->and(photosPendingFiles($import))->toHaveCount(0);
});

it('ends the browser failure streak when the queue makes progress', function (): void {
    $import = techDebtImport([
        ['B-1', 'Стол', 'Мебель', '100', ''],
        ['B-2', 'Стул', 'Мебель', '100', ''],
        ['B-3', 'Лампа', 'Мебель', '100', ''],
    ], ['chunk_size' => 1]);
    $runner = app(ImportRunner::class);
    $runner->start($import);
    actingAs(User::factory()->admin()->create());
    $key = ImportRunner::browserFailuresKey($import);

    Cache::put($key, 2, 3600);
    expect($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Chunk)
        ->and(Cache::has($key))->toBeFalse();

    // Two failures, a queue step in between, two more failures: never three in a row, the import survives.
    $staging = (string) $import->refresh()->staging_path;
    $content = Storage::disk('local')->get($staging);
    $url = route('admin.imports.process-next', $import);

    Storage::disk('local')->delete($staging);
    $this->postJson($url)->assertStatus(503);
    $this->postJson($url)->assertStatus(503);
    Storage::disk('local')->put($staging, $content);
    expect($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Chunk);
    Storage::disk('local')->delete($staging);
    $this->postJson($url)->assertStatus(503);
    $this->postJson($url)->assertStatus(503);

    expect($import->refresh()->status)->not->toBe(ImportStatus::Failed);
});

it('removes the freshly written files when another worker attached the same photo first', function (): void {
    $product = Product::factory()->create();
    $file = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($file, ImportHarness::jpeg());
    $hash = sha1('https://img.test/race.jpg');
    $winner = null;

    // The competing worker commits its image right while this one waits for the product row lock.
    Product::retrieved(function () use ($product, $hash, &$winner): void {
        $winner ??= ProductImage::factory()->create(['product_id' => $product->id, 'source_hash' => $hash]);
    });

    $image = app(ImageProcessor::class)->attachToProduct($product, $file, null, $hash, true);

    expect($image->id)->toBe($winner->id)
        ->and($product->images()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles('products/'.$product->id))->toBe([])
        ->and(is_file($file))->toBeFalse();
});

it('hands back only the unfinished tasks when the heartbeat throws after a counted task', function (): void {
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', '']]);
    $products = Product::factory()->count(2)->create();
    $tasks = $products->map(fn (Product $p): array => ['type' => 'product', 'id' => $p->id, 'refs' => [], 'mode' => 'replace', 'row' => 2, 'unchanged' => true])->all();
    $remaining = null;

    $run = function () use ($import, $tasks, &$remaining) {
        return app(ImportPhotoProcessor::class)->run(
            $import,
            $tasks,
            microtime(true) + 30,
            function (): void {
                throw new RuntimeException('beat failed');
            },
            function (array $left) use (&$remaining): void {
                $remaining = $left;
            },
        );
    };

    expect($run)->toThrow(RuntimeException::class);
    // The first task was counted before the heartbeat: it must not come back.
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]['id'])->toBe($products[1]->id)
        ->and($import->refresh()->unchanged_count)->toBe(1);
});

it('hands back the unfinished tasks when the counters update fails', function (): void {
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', '']]);
    $product = Product::factory()->create();
    $tasks = [['type' => 'product', 'id' => $product->id, 'refs' => [], 'mode' => 'replace', 'row' => 2, 'unchanged' => true]];
    $remaining = null;

    // The UPDATE of the counters fails (a database hiccup).
    DB::listen(function ($query): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'unchanged_count')) {
            throw new RuntimeException('counters failed');
        }
    });

    $run = function () use ($import, $tasks, &$remaining) {
        return app(ImportPhotoProcessor::class)->run($import, $tasks, microtime(true) + 30, null, function (array $left) use (&$remaining): void {
            $remaining = $left;
        });
    };

    expect($run)->toThrow(RuntimeException::class)
        ->and($remaining)->toBe([]);
});

it('does not count a run that only deferred its tasks as work', function (): void {
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', '']]);
    $product = Product::factory()->create();
    $tasks = [['type' => 'product', 'id' => $product->id, 'refs' => [], 'mode' => 'replace', 'row' => 2]];
    $processor = app(ImportPhotoProcessor::class);

    expect($processor->run($import, $tasks, microtime(true) - 1))->toBe($tasks)
        ->and($processor->didWork())->toBeFalse();

    expect($processor->run($import, $tasks, microtime(true) + 30))->toBe([])
        ->and($processor->didWork())->toBeTrue();
});

it('keeps the photos-changed mark of a task whose photo step threw after the images changed', function (): void {
    Http::fake(['*' => fn () => Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
    $import = techDebtImport([['A-1', 'Стол', 'Мебель', '100', '']]);
    $product = Product::factory()->create();
    ProductImage::factory()->create(['product_id' => $product->id, 'source_hash' => 'old-hash']);
    $tasks = [['type' => 'product', 'id' => $product->id, 'refs' => ['https://img.test/new.jpg'], 'mode' => 'replace', 'row' => 2, 'unchanged' => true]];
    $saved = null;
    $beats = 0;
    $processor = app(ImportPhotoProcessor::class);

    // Beat 1 follows the download, beat 2 the attached image: the second one fails.
    $first = function () use ($processor, $import, $tasks, &$beats, &$saved) {
        return $processor->run($import, $tasks, microtime(true) + 30, function () use (&$beats): void {
            if (++$beats === 2) {
                throw new RuntimeException('boom');
            }
        }, null, function (array $all) use (&$saved): void {
            $saved = $all;
        });
    };

    expect($first)->toThrow(RuntimeException::class)
        ->and($saved[0]['photos_changed'] ?? false)->toBeTrue();

    // The retry runs the saved task: the image is there already, but the row still counts as updated.
    expect($processor->run($import, $saved, microtime(true) + 30))->toBe([]);
    $import->refresh();

    expect($import->updated_count)->toBe(1)
        ->and($import->unchanged_count)->toBe(0)
        ->and($product->images()->count())->toBe(1);
});
