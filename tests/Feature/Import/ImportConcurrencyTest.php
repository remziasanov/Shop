<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Exceptions\ImageFetchDeadlineException;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Import\HostResolver;
use App\Services\Import\ImageFetcher;
use App\Services\Import\ImportAnalyzer;
use App\Services\Import\ImportPhotoProcessor;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportStep;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHostResolver;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    app()->instance(HostResolver::class, new FakeHostResolver);
});

function concurrencyImport(): Import
{
    return ImportHarness::analyzed(ImportFiles::csv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Фото'],
        ['A-1', 'Стол', 'Мебель', '100', 'https://img.test/1.jpg; https://img.test/2.jpg'],
        ['A-2', 'Стул', 'Мебель', '100', 'https://img.test/3.jpg'],
    ]));
}

function concurrencyJpeg(): mixed
{
    return Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg']);
}

it('does not finish the import while another worker is in the middle of the photos of the last chunk', function () {
    $import = concurrencyImport();
    $runner = app(ImportRunner::class);
    $runner->start($import);

    $steps = [];
    $nested = false;

    // Worker A downloads the first photo; at that moment worker B (browser fallback) comes in.
    Http::fake(['*' => function () use (&$nested, &$steps, $runner, $import) {
        if (! $nested) {
            $nested = true;

            // The working file written under the lock is what protects the photos.
            $working = glob(Storage::disk('local')->path('imports/'.$import->id).'/photos-working-*.json');
            expect($working)->toHaveCount(1);

            $steps[] = $runner->step($import->fresh(), 20.0);
            $steps[] = $runner->step($import->fresh(), 20.0);

            expect($import->fresh()->status)->not->toBe(ImportStatus::Completed)
                ->and(Storage::disk('local')->exists('imports/'.$import->id))->toBeTrue();
        }

        return concurrencyJpeg();
    }]);

    expect($runner->step($import->fresh(), 20.0))->toBe(ImportStep::Chunk);

    // B never finished the import and never ran the tasks of A.
    expect($steps)->toBe([ImportStep::Waiting, ImportStep::Waiting]);

    // After A is done, the next call finishes it and all three photos exist.
    expect($runner->step($import->fresh(), 20.0))->toBe(ImportStep::Finished);

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(ProductImage::query()->count())->toBe(3)
        ->and(glob(Storage::disk('local')->path('imports/'.$import->id).'/photos-*.json'))->toBe([]);
});

it('does not finish while the run lock is held by a worker that has not stored its photo tasks yet', function () {
    $import = concurrencyImport();
    $runner = app(ImportRunner::class);
    $runner->start($import);

    // Chunk data applied, tasks not yet on disk: simulated by holding the lock around the check.
    $lock = Cache::lock('import:'.$import->id.':run', 60);
    expect($lock->get())->toBeTrue();

    Import::query()->whereKey($import->id)->update(['status' => 'processing', 'next_chunk' => 1, 'chunks_done' => 1]);

    expect($runner->finish($import->fresh()))->toBeFalse()
        ->and($import->fresh()->status)->toBe(ImportStatus::Processing);

    $lock->release();
});

it('takes back the photo tasks of a dead worker after the stale timeout and then finishes', function () {
    $import = concurrencyImport();
    $runner = app(ImportRunner::class);
    $runner->start($import);

    // Worker A claims the chunk and stores its photo tasks, then dies: only an old working file is left behind.
    expect($runner->step($import->fresh(), 0.0))->toBe(ImportStep::Chunk);

    $directory = Storage::disk('local')->path('imports/'.$import->id);
    $pending = glob($directory.'/photos-pending-*.json');
    expect($pending)->toHaveCount(1);
    rename($pending[0], $directory.'/photos-working-dead.json');

    Http::fake(['*' => fn () => concurrencyJpeg()]);

    // Fresh working file: still in use, nobody finishes.
    expect($runner->step($import->fresh(), 20.0))->toBe(ImportStep::Waiting);

    touch($directory.'/photos-working-dead.json', time() - ImportRunner::STALE_CLAIM_SECONDS - 5);

    expect($runner->step($import->fresh(), 20.0))->toBe(ImportStep::Photos)
        ->and($runner->step($import->fresh(), 20.0))->toBe(ImportStep::Finished)
        ->and(ProductImage::query()->count())->toBe(3);
});

// ---------------------------------------------------------------- deadline (IM-10)

it('does not start a download that does not fit into the remaining budget', function () {
    Http::fake(['*' => fn () => concurrencyJpeg()]);
    $fetcher = app(ImageFetcher::class);
    $target = sys_get_temp_dir().'/deadline-'.bin2hex(random_bytes(4)).'.img';

    // Less than MIN_WINDOW left: not started at all.
    expect(fn () => $fetcher->fetch('https://img.test/a.jpg', $target, microtime(true) + ImageFetcher::MIN_WINDOW - 1))->toThrow(ImageFetchDeadlineException::class);
    Http::assertNothingSent();

    @unlink($target);
});

it('limits the request timeout to the remaining budget minus the reserve', function () {
    $options = [];
    Http::globalMiddleware(function (callable $handler) use (&$options): callable {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options[] = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(['*' => fn () => concurrencyJpeg()]);

    $fetcher = app(ImageFetcher::class);
    $target = sys_get_temp_dir().'/deadline-'.bin2hex(random_bytes(4)).'.img';

    $fetcher->fetch('https://img.test/a.jpg', $target, microtime(true) + 10.0);
    $fetcher->fetch('https://img.test/b.jpg', $target, microtime(true) + 100.0);
    $fetcher->fetch('https://img.test/c.jpg', $target);
    @unlink($target);

    expect($options)->toHaveCount(3)
        ->and($options[0]['timeout'])->toBeGreaterThan(7.0)->toBeLessThanOrEqual(8.0)
        ->and($options[1]['timeout'])->toBe(ImageFetcher::REQUEST_TIMEOUT)
        ->and($options[2]['timeout'])->toBe(ImageFetcher::REQUEST_TIMEOUT);
});

it('never uses a proxy from the environment for photo downloads', function () {
    $options = [];
    Http::globalMiddleware(function (callable $handler) use (&$options): callable {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options[] = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(['*' => fn () => concurrencyJpeg()]);

    $target = sys_get_temp_dir().'/proxy-'.bin2hex(random_bytes(4)).'.img';
    app(ImageFetcher::class)->fetch('https://img.test/a.jpg', $target);
    @unlink($target);

    expect($options)->toHaveCount(1)
        ->and($options[0]['proxy'])->toBe('')
        ->and($options[0]['curl'][CURLOPT_PROXY])->toBe('')
        ->and($options[0]['curl'][CURLOPT_RESOLVE])->toBe(['img.test:443:93.184.216.34']);
});

it('defers photo tasks whose download no longer fits the browser budget instead of processing them', function () {
    Http::fake(['*' => fn () => concurrencyJpeg()]);
    $import = concurrencyImport();
    $runner = app(ImportRunner::class);
    $runner->start($import);

    // Budget of 3 s < MIN_WINDOW: the data step runs, the photos stay pending, nothing is downloaded.
    expect($runner->step($import->fresh(), 3.0))->toBe(ImportStep::Chunk);
    Http::assertNothingSent();

    $directory = Storage::disk('local')->path('imports/'.$import->id);
    expect(glob($directory.'/photos-pending-*.json'))->toHaveCount(1)
        ->and(glob($directory.'/photos-working-*.json'))->toBe([])
        ->and(ProductImage::query()->count())->toBe(0)
        ->and($import->fresh()->status)->not->toBe(ImportStatus::Completed);

    // The next request has the whole budget.
    expect($runner->step($import->fresh(), ImportRunner::BROWSER_BUDGET))->toBe(ImportStep::Photos)
        ->and($runner->step($import->fresh(), 20.0))->toBe(ImportStep::Finished)
        ->and(ProductImage::query()->count())->toBe(3);
});

it('stops attaching images when the deadline passes between two images and continues later without duplicates', function () {
    $import = ImportHarness::analyzed(ImportFiles::csv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Фото'],
        ['Z-1', 'Стол', 'Мебель', '100', 'a.jpg; b.jpg; c.jpg'],
    ]));
    ImportHarness::attachZip($import, ImportHarness::zip([
        'a.jpg' => ImportHarness::jpeg(100, 80, 10),
        'b.jpg' => ImportHarness::jpeg(100, 80, 100),
        'c.jpg' => ImportHarness::jpeg(100, 80, 200),
    ]));
    $import->refresh();

    $runner = app(ImportRunner::class);
    $runner->start($import);

    // Chunk data step only (budget 0 → photos are not started), then drive the processor directly.
    expect($runner->step($import->fresh(), 0.0))->toBe(ImportStep::Chunk);

    $pendingFile = glob(Storage::disk('local')->path('imports/'.$import->id).'/photos-pending-*.json')[0];
    $tasks = json_decode((string) file_get_contents($pendingFile), true);
    $processor = app(ImportPhotoProcessor::class);

    // Fake clock: the deadline passes right after the first image is attached (heartbeat call 4 = after attach 1).
    $deadline = microtime(true) + 1.5;
    $calls = 0;
    $left = $processor->run($import->fresh(), $tasks, $deadline, function () use (&$calls, $deadline): void {
        if (++$calls === 4) {
            while (microtime(true) < $deadline) {
                usleep(20000);
            }
        }
    });

    $product = Product::query()->where('sku', 'Z-1')->firstOrFail();

    expect($product->images()->count())->toBe(1)
        ->and($left)->toHaveCount(1)
        ->and($left[0]['photos_changed'])->toBeTrue();

    $processor->run($import->fresh(), $left, microtime(true) + 30.0);

    expect($product->images()->orderBy('sort_order')->pluck('source_hash')->all())
        ->toBe([sha1('file:a.jpg'), sha1('file:b.jpg'), sha1('file:c.jpg')]);
});

// ---------------------------------------------------------------- analysis lock

it('does nothing when another analysis holds the lock or the import is already analyzed', function () {
    $import = Import::factory()->create(['file_path' => 'pending']);
    $relative = 'imports/'.$import->id.'/source.csv';
    Storage::disk('local')->put($relative, (string) file_get_contents(ImportFiles::csv([['Артикул', 'Название', 'Цена'], ['S-1', 'Стол', '10']])));
    $import->update(['file_path' => $relative, 'status' => ImportStatus::Analyzing]);

    $analyzer = app(ImportAnalyzer::class);
    $lock = Cache::lock('import:'.$import->id.':analyze', 60);
    expect($lock->get())->toBeTrue();

    // Loser: nothing is staged, status unchanged.
    $analyzer->analyze($import->fresh());
    expect($import->fresh()->status)->toBe(ImportStatus::Analyzing)
        ->and($import->fresh()->staging_path)->toBeNull();

    $lock->release();

    $analyzed = $analyzer->analyze($import->fresh());
    expect($analyzed->status)->toBe(ImportStatus::Analyzed)->and($analyzed->staging_path)->not->toBeNull();

    // A later duplicate call (queue job after «Проверить сейчас») must not restage an analyzed import.
    $staged = file_get_contents(Storage::disk('local')->path((string) $analyzed->staging_path));
    $again = $analyzer->analyze($import->fresh());

    expect($again->status)->toBe(ImportStatus::Analyzed)
        ->and(file_get_contents(Storage::disk('local')->path((string) $again->staging_path)))->toBe($staged);
});
