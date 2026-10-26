<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Services\Import\ImportOptions;
use Carbon\CarbonInterface;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One import run (IM-01…IM-15). Files live on the private `local` disk under `imports/{id}/`;
 * `file_path`, `staging_path`, `categories_staging_path` are paths relative to that disk.
 *
 * `mapping`: column index => field key | "attr:<Name>" | null (see ColumnMapper).
 * `analysis`: {headers, mapping_warnings, summary: {create, update, skip, errors, warnings, restored, rows_total,
 * categories_rows, categories_errors}, preview: list<row>} written by ImportAnalyzer.
 * `chunk_offsets`: byte offset of every chunk start inside the staging JSONL.
 *
 * @property int $id
 * @property int|null $user_id
 * @property ImportStatus $status
 * @property ImportMode $mode
 * @property string $original_name
 * @property string $file_path
 * @property string|null $sheet
 * @property MediaSource $media_source
 * @property string|null $media_path
 * @property array<int, string|null>|null $mapping
 * @property array<string, mixed>|null $options
 * @property int $rows_total
 * @property int $rows_processed
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $error_count
 * @property int $warning_count
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property string|null $failure_message
 * @property string|null $staging_path
 * @property string|null $categories_staging_path
 * @property int $chunk_size
 * @property int $chunks_total
 * @property int $next_chunk
 * @property int $chunks_done
 * @property list<int>|null $chunk_offsets
 * @property array<string, mixed>|null $analysis
 * @property bool $dry_run
 * @property CarbonInterface|null $last_activity_at
 * @property-read User|null $user
 */
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'mode',
        'original_name',
        'file_path',
        'sheet',
        'media_source',
        'media_path',
        'mapping',
        'options',
        'rows_total',
        'rows_processed',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'warning_count',
        'started_at',
        'finished_at',
        'failure_message',
        'staging_path',
        'categories_staging_path',
        'chunk_size',
        'chunks_total',
        'next_chunk',
        'chunks_done',
        'chunk_offsets',
        'analysis',
        'dry_run',
        'last_activity_at',
    ];

    protected static function booted(): void
    {
        // Deleting an import removes its files (source, staging, errors report, uploaded ZIP).
        static::deleted(function (Import $import): void {
            Storage::disk('local')->deleteDirectory('imports/'.$import->id);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'mode' => ImportMode::class,
            'media_source' => MediaSource::class,
            'mapping' => 'array',
            'options' => 'array',
            'chunk_offsets' => 'array',
            'analysis' => 'array',
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }

    /**
     * Typed options; the `mode` column wins over the mode stored inside the JSON, `dry_run` likewise.
     */
    public function importOptions(): ImportOptions
    {
        $data = $this->options ?? [];
        $data['dry_run'] = $this->dry_run;

        return ImportOptions::fromArray($data, $this->mode);
    }

    /**
     * Share of processed rows, 0–100.
     */
    public function progressPercent(): int
    {
        if ($this->status === ImportStatus::Completed || $this->status === ImportStatus::CompletedWithErrors) {
            return 100;
        }

        if ($this->rows_total <= 0) {
            return 0;
        }

        return (int) min(100, floor($this->rows_processed * 100 / $this->rows_total));
    }
}
