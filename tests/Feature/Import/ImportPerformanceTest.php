<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

// IM-15: 10 000 rows without photos in well under 10 minutes and within 128 MB (here: generous 120 s on SQLite: ~35 s locally, slow CI runners vary).
it('imports 10 000 rows through processNext within time and memory limits', function () {
    Storage::fake('local');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);

    $rows = [['Артикул', 'Название', 'Категория', 'Цена', 'Остаток', 'Бренд', 'Х: Цвет']];
    for ($i = 1; $i <= 10000; $i++) {
        $rows[] = ['P-'.$i, 'Товар '.$i, 'Каталог / Раздел '.($i % 10).' / Группа '.($i % 7), (string) (100 + $i).',50', (string) ($i % 30), 'Бренд '.($i % 5), 'Цвет '.($i % 4)];
    }
    $path = ImportFiles::csv($rows);
    unset($rows);

    $started = microtime(true);

    $import = ImportHarness::analyzed($path);
    $analyzed = microtime(true) - $started;

    // Peak of the processing loop only: reset right before it, compare with the usage at that moment (PHP 8.2+),
    // so memory taken by earlier tests of the same process does not count.
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage();
    $import = ImportHarness::run($import, 5000);

    $elapsed = microtime(true) - $started;
    $peakMb = (memory_get_peak_usage() - $memoryBefore) / 1048576;

    fwrite(STDERR, sprintf("\n[IM-15] 10000 rows: analysis %.1fs, total %.1fs, peak memory growth during processing %.0f MB\n", $analyzed, $elapsed, $peakMb));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_count)->toBe(10000)
        ->and(Product::count())->toBe(10000)
        ->and($elapsed)->toBeLessThan(120)
        ->and($peakMb)->toBeLessThan(64);
})->group('slow');
