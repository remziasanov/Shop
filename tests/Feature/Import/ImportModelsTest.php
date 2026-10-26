<?php

declare(strict_types=1);

use App\Enums\EmptyCellPolicy;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\ImportIssueLevel;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Enums\PhotoMode;
use App\Models\Export;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\PriceHistory;
use App\Models\User;
use App\Services\Import\ImportOptions;
use Illuminate\Support\Facades\Schema;

it('has the TZ section 7 and D-033 columns', function () {
    expect(Schema::hasColumns('imports', [
        'user_id', 'status', 'mode', 'original_name', 'file_path', 'sheet', 'media_source', 'media_path', 'mapping', 'options',
        'rows_total', 'rows_processed', 'created_count', 'updated_count', 'skipped_count', 'error_count', 'warning_count',
        'started_at', 'finished_at', 'failure_message', 'staging_path', 'categories_staging_path', 'chunk_size', 'chunks_total',
        'next_chunk', 'chunks_done', 'chunk_offsets', 'analysis', 'dry_run', 'last_activity_at', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('import_issues', ['import_id', 'row_number', 'column', 'level', 'message', 'value', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumns('exports', ['user_id', 'type', 'status', 'params', 'file_path', 'rows', 'failure_message']))->toBeTrue()
        ->and(Schema::hasTable('notifications'))->toBeTrue();
});

it('creates imports with sane defaults and casts', function () {
    $import = Import::factory()->create(['mapping' => [0 => 'sku'], 'options' => ['mode' => 'create_update'], 'chunk_offsets' => [0, 120]]);
    $import = $import->fresh();

    expect($import->status)->toBe(ImportStatus::Uploaded)
        ->and($import->mode)->toBe(ImportMode::CreateUpdate)
        ->and($import->media_source)->toBe(MediaSource::None)
        ->and($import->chunk_size)->toBe(200)
        ->and($import->next_chunk)->toBe(0)
        ->and($import->dry_run)->toBeFalse()
        ->and($import->mapping)->toBe([0 => 'sku'])
        ->and($import->chunk_offsets)->toBe([0, 120])
        ->and($import->user)->toBeInstanceOf(User::class);
});

it('computes progress and finished state', function () {
    expect(Import::factory()->make(['rows_total' => 0])->progressPercent())->toBe(0)
        ->and(Import::factory()->make(['rows_total' => 450, 'rows_processed' => 200, 'status' => ImportStatus::Processing])->progressPercent())->toBe(44)
        ->and(Import::factory()->make(['rows_total' => 450, 'rows_processed' => 450])->progressPercent())->toBe(100)
        ->and(Import::factory()->make(['rows_total' => 10, 'rows_processed' => 3, 'status' => ImportStatus::Completed])->progressPercent())->toBe(100);

    foreach (ImportStatus::cases() as $status) {
        $finished = in_array($status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors, ImportStatus::Failed, ImportStatus::Cancelled], true);

        expect($status->isFinished())->toBe($finished);
    }
});

it('cascades issues with the import and nulls the user', function () {
    $import = Import::factory()->create();
    ImportIssue::factory()->count(2)->for($import)->create();
    ImportIssue::factory()->warning()->for($import)->create();

    expect($import->issues)->toHaveCount(3)
        ->and($import->issues()->where('level', ImportIssueLevel::Warning->value)->count())->toBe(1);

    $import->user->delete();
    expect($import->fresh()->user_id)->toBeNull();

    $import->delete();
    expect(ImportIssue::count())->toBe(0);
});

it('stores exports', function () {
    $export = Export::factory()->create(['params' => ['category_id' => 3]]);

    expect($export->fresh()->type)->toBe(ExportType::Catalog)
        ->and($export->fresh()->status)->toBe(ExportStatus::Queued)
        ->and($export->fresh()->params)->toBe(['category_id' => 3])
        ->and($export->rows)->toBe(0);
});

it('lets price history reference an import', function () {
    $import = Import::factory()->create();
    $history = PriceHistory::factory()->create(['import_id' => $import->id]);

    expect($history->fresh()->import_id)->toBe($import->id);
});

it('round-trips import options and falls back to defaults', function () {
    $options = new ImportOptions(ImportMode::FullSync, false, '>', EmptyCellPolicy::Clear, PhotoMode::Append, true);

    expect(ImportOptions::fromArray($options->toArray()))->toEqual($options)
        ->and(ImportOptions::fromArray(null))->toEqual(new ImportOptions)
        ->and(ImportOptions::fromArray(['category_separator' => '#', 'photo_mode' => 'x', 'mode' => 'nope'])->categorySeparator)->toBe('/')
        ->and((new ImportOptions)->toArray())->toBe([
            'mode' => 'create_update', 'create_categories' => true, 'category_separator' => '/',
            'empty_cell_policy' => 'keep', 'photo_mode' => 'replace', 'dry_run' => false,
        ]);

    $import = Import::factory()->make(['mode' => ImportMode::PricesStock, 'dry_run' => true, 'options' => ['mode' => 'full_sync', 'category_separator' => '|']]);

    expect($import->importOptions()->mode)->toBe(ImportMode::PricesStock)
        ->and($import->importOptions()->dryRun)->toBeTrue()
        ->and($import->importOptions()->categorySeparator)->toBe('|');
});

it('has Russian labels for every enum case', function () {
    foreach ([ImportStatus::class, ImportMode::class, EmptyCellPolicy::class, PhotoMode::class, MediaSource::class, ImportIssueLevel::class, ExportType::class, ExportStatus::class] as $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->label())->not->toStartWith('import.')->and($case->label())->toMatch('/[А-Яа-я]/u');
        }
    }
});
