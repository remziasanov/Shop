<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportField;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Enums\PhotoMode;
use App\Exceptions\ImportFileException;
use App\Jobs\AnalyzeImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Server side of the import wizard (IM-01, IM-02, IM-03, IM-05, NF-11): upload limits, accepted files, creation of
 * the import record with its files, server media listing, mapping and options validation, start of the analysis.
 *
 * Uploaded files arrive on the private `local` disk under `imports/uploads/` (random names) and are moved to
 * `imports/{id}/` under new random names when the import is created. Nothing here trusts the client: extensions,
 * MIME types, file signatures, the mapping and the chosen server entry are re-checked.
 */
final class ImportWizard
{
    public const DISK = 'local';

    public const UPLOAD_DIRECTORY = 'imports/uploads';

    /** Largest import file, MB (further limited by the PHP upload settings). */
    public const MAX_FILE_MB = 50;

    /** @var list<string> */
    public const FILE_EXTENSIONS = ['xlsx', 'csv'];

    /**
     * text/plain and application/vnd.ms-excel: CSV from Windows; application/zip: how fileinfo may see an .xlsx.
     *
     * @var list<string>
     */
    public const FILE_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'text/csv',
        'application/csv',
        'text/plain',
        'application/vnd.ms-excel',
    ];

    /** @var list<string> */
    public const ZIP_MIMES = ['application/zip', 'application/x-zip-compressed', 'application/x-zip'];

    public function __construct(
        private readonly ImportReader $reader,
        private readonly ColumnMapper $mapper,
        private readonly MappingMemory $memory,
    ) {}

    // ------------------------------------------------------------------ limits

    /**
     * min(50 MB, upload_max_filesize, post_max_size) in bytes.
     */
    public function maxUploadBytes(): int
    {
        $limits = [self::MAX_FILE_MB * 1024 * 1024];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity((string) ini_get($setting));

            // 0 means "no limit" for post_max_size.
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    public function limitsHint(): string
    {
        $mb = fn (int $bytes): string => rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',');

        return __('import_ui.file.limits', [
            'size' => $mb($this->maxUploadBytes()),
            'upload' => (string) ini_get('upload_max_filesize'),
            'post' => (string) ini_get('post_max_size'),
        ]);
    }

    public static function hasAcceptedExtension(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::FILE_EXTENSIONS, true);
    }

    public static function isZipName(string $name): bool
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'zip';
    }

    // ------------------------------------------------------------------ server media (IM-02 c)

    /**
     * Directories and .zip files of storage/app/import-media (no symbolic links, no dot files): name => label.
     *
     * @return array<string, string>
     */
    public function serverMediaEntries(): array
    {
        $base = storage_path('app/'.MediaResolver::SERVER_DIRECTORY);

        if (! is_dir($base)) {
            return [];
        }

        $entries = [];

        foreach (scandir($base) ?: [] as $name) {
            $path = $base.DIRECTORY_SEPARATOR.$name;

            if ($name === '.' || $name === '..' || str_starts_with($name, '.') || is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $entries[$name] = __('import_ui.file.server_entry_dir', ['name' => $name]);
            } elseif (is_file($path) && self::isZipName($name)) {
                $entries[$name] = __('import_ui.file.server_entry_zip', ['name' => $name, 'size' => $this->humanSize((int) filesize($path))]);
            }
        }

        ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        return $entries;
    }

    // ------------------------------------------------------------------ step 1: create the import

    /**
     * Create the import from files already stored on the private disk (paths relative to the disk).
     * The stored files are consumed (moved into `imports/{id}/`); on failure they are deleted.
     *
     * @throws ImportFileException
     * @throws ValidationException
     */
    public function createImport(User $user, string $storedFile, string $originalName, MediaSource $source, ?string $storedZip = null, ?string $serverEntry = null): Import
    {
        $disk = Storage::disk(self::DISK);
        $originalName = $this->cleanName($originalName);

        try {
            $this->assertStoredUpload($storedFile);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (! in_array($extension, self::FILE_EXTENSIONS, true)) {
                throw ValidationException::withMessages(['data.file' => __('import_ui.upload_errors.extension')]);
            }

            $this->assertSignature($disk->path($storedFile), $extension);

            // Fails with a readable message for unreadable files; also picks the product sheet.
            $sheets = $this->reader->sheets($disk->path($storedFile));
            $sheet = $this->reader->guessProductSheet($sheets);

            if ($source === MediaSource::Zip) {
                if ($storedZip === null || $storedZip === '') {
                    throw ValidationException::withMessages(['data.media_zip' => __('import_ui.upload_errors.zip_required')]);
                }

                $this->assertStoredUpload($storedZip);

                if (! $this->isZip($disk->path($storedZip))) {
                    throw ValidationException::withMessages(['data.media_zip' => __('import_ui.upload_errors.zip_extension')]);
                }
            } elseif ($source === MediaSource::ServerDir) {
                if ($serverEntry === null || $serverEntry === '') {
                    throw ValidationException::withMessages(['data.media_server_entry' => __('import_ui.upload_errors.server_entry_required')]);
                }

                if (! array_key_exists($serverEntry, $this->serverMediaEntries())) {
                    throw ValidationException::withMessages(['data.media_server_entry' => __('import_ui.upload_errors.server_entry_invalid')]);
                }
            }

            $import = Import::query()->create([
                'user_id' => $user->id,
                'status' => ImportStatus::Uploaded,
                'mode' => ImportMode::CreateUpdate,
                'original_name' => $originalName,
                'file_path' => 'pending',
                'sheet' => $sheet,
                'media_source' => $source,
                'chunk_size' => 200,
                'options' => ImportOptions::fromArray(null)->toArray(),
            ]);

            $directory = 'imports/'.$import->id;
            $target = $directory.'/'.Str::random(40).'.'.$extension;
            $mediaPath = null;

            try {
                $disk->makeDirectory($directory);

                if (! $disk->move($storedFile, $target)) {
                    throw new ImportFileException(__('import_ui.upload_errors.unreadable'));
                }

                if ($source === MediaSource::Zip) {
                    $mediaPath = $directory.'/'.Str::random(40).'.zip';

                    if (! $disk->move($storedZip, $mediaPath)) {
                        throw new ImportFileException(__('import_ui.upload_errors.unreadable'));
                    }
                } elseif ($source === MediaSource::ServerDir) {
                    $mediaPath = $serverEntry;
                }

                $import->forceFill(['file_path' => $target, 'media_path' => $mediaPath])->save();
            } catch (\Throwable $e) {
                $import->delete();

                throw $e;
            }

            return $import;
        } catch (\Throwable $e) {
            foreach (array_filter([$storedFile, $storedZip]) as $path) {
                if (str_starts_with($path, self::UPLOAD_DIRECTORY.'/')) {
                    $disk->delete($path);
                }
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------ step 2: mapping and options

    /**
     * Sheet names of the import file.
     *
     * @return list<string>
     */
    public function sheets(Import $import): array
    {
        return $this->reader->sheets(Storage::disk(self::DISK)->path($import->file_path));
    }

    /**
     * Header row, sample rows and the initial mapping (remembered or automatic) of a sheet.
     *
     * @return array{headers: list<string>, samples: array<int, list<string>>, mapping: array<int, string>, warnings: list<string>}
     *
     * @throws ImportFileException
     */
    public function columns(Import $import, ?string $sheet, int $sampleRows = 3, bool $useSavedMapping = true): array
    {
        $path = Storage::disk(self::DISK)->path($import->file_path);
        $sample = $this->reader->sample($path, $sheet, $sampleRows);
        $headers = $sample['headers'];

        if ($headers === []) {
            throw new ImportFileException(__('import.errors.empty_file'));
        }

        $width = count($headers);
        $mapping = null;

        if ($useSavedMapping && is_array($import->mapping) && $import->mapping !== [] && ($import->analysis['headers'] ?? null) === $headers) {
            $mapping = $import->mapping;
        }

        $result = $mapping === null
            ? $this->mapper->dedupe($this->memory->suggest($headers, $import->id), $headers)
            : $this->mapper->dedupe($mapping, $headers);

        // Repeated targets found by the automatic mapping are dropped from the mapping but must be reported.
        $warnings = $mapping === null ? $this->mapper->autoMapWithWarnings($headers)->warnings($headers) : $result->warnings($headers);

        $samples = [];

        foreach ($sample['rows'] as $number => $row) {
            $samples[$number] = array_map(fn (?string $cell): string => (string) $cell, array_pad($row, $width, null));
        }

        return [
            'headers' => array_map(fn (?string $header): string => (string) $header, $headers),
            'samples' => $samples,
            'mapping' => array_map(fn (?string $target): string => (string) $target, array_pad($result->mapping, $width, null)),
            'warnings' => $warnings,
        ];
    }

    /**
     * Options of the target select of every column: «Игнорировать», catalog fields, «Характеристика: <header>».
     *
     * @param  list<string>  $headers
     * @param  array<int, string>  $mapping
     * @return array<string, string>
     */
    public function targetOptions(array $headers, array $mapping = []): array
    {
        $options = ['' => __('import_ui.mapping.ignore')];

        foreach (ImportField::options() as $key => $label) {
            $options[$key] = $label;
        }

        $attributes = [];

        foreach ($headers as $header) {
            $name = ColumnMapper::attributeNameFromHeader($header) ?? trim($header);

            if ($name !== '') {
                $attributes[ImportField::attributeKey($name)] = true;
            }
        }

        foreach ($mapping as $target) {
            if (ImportField::attributeName($target) !== null) {
                $attributes[$target] = true;
            }
        }

        foreach (array_keys($attributes) as $key) {
            $options[$key] = __('import_ui.mapping.attribute_option', ['name' => (string) ImportField::attributeName($key)]);
        }

        return $options;
    }

    /**
     * Validate the mapping typed by the user against the real header row and remove repeated targets.
     *
     * @param  array<int|string, mixed>  $input
     * @param  list<string|null>  $headers
     * @return array<int, string|null>
     *
     * @throws ValidationException
     */
    public function validateMapping(array $input, array $headers): array
    {
        $mapping = [];

        foreach (array_keys($headers) as $index) {
            $target = $input[$index] ?? null;
            $target = is_string($target) ? trim($target) : '';

            if ($target === '') {
                $mapping[$index] = null;

                continue;
            }

            $valid = ImportField::tryFrom($target) !== null
                || (ImportField::attributeName($target) !== null && mb_strlen($target) <= 255);

            if (! $valid) {
                throw ValidationException::withMessages([
                    'mapping' => __('import_ui.mapping.invalid_target', ['header' => (string) ($headers[$index] ?? '')]),
                ]);
            }

            $mapping[$index] = $target;
        }

        $mapping = $this->mapper->dedupe($mapping, $headers)->mapping;

        if (! in_array(ImportField::Sku->value, $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => __('import_ui.mapping.sku_required')]);
        }

        return $mapping;
    }

    /**
     * Validate the options. Full synchronization is rejected for users who may not use it, whatever the client sent.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function validateOptions(array $input, User $user): ImportOptions
    {
        $validator = Validator::make($input, [
            'mode' => ['required', Rule::enum(ImportMode::class)],
            'create_categories' => ['boolean'],
            'category_separator' => ['required', Rule::in(ImportOptions::SEPARATORS)],
            'empty_cell_policy' => ['required', Rule::enum(EmptyCellPolicy::class)],
            'photo_mode' => ['required', Rule::enum(PhotoMode::class)],
            'dry_run' => ['boolean'],
        ]);

        if ($validator->fails()) {
            $messages = [];

            foreach ($validator->errors()->keys() as $key) {
                $messages['options.'.$key] = __('import_ui.mapping.invalid_option');
            }

            throw ValidationException::withMessages($messages);
        }

        $validated = $validator->validated();

        $options = ImportOptions::fromArray($validated);

        if ($options->mode === ImportMode::FullSync && ! $user->can('useFullSync', Import::class)) {
            throw ValidationException::withMessages(['options.mode' => __('import_ui.mapping.full_sync_forbidden')]);
        }

        return $options;
    }

    /**
     * Store sheet, mapping (remembered for the next import) and options; the import stays `uploaded` until
     * {@see startAnalysis()}.
     *
     * @param  array<int|string, mixed>  $mapping
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     * @throws ImportFileException
     */
    public function saveMapping(Import $import, User $user, ?string $sheet, array $mapping, array $options): Import
    {
        $validOptions = $this->validateOptions($options, $user);
        $sheets = $this->sheets($import);

        if ($sheet !== null && $sheet !== '' && ! in_array($sheet, $sheets, true)) {
            throw new ImportFileException(__('import.errors.sheet_not_found', ['sheet' => $sheet]));
        }

        $sheet = ($sheet === null || $sheet === '') ? $this->reader->guessProductSheet($sheets) : $sheet;

        // Headers come from the file, never from the client.
        $headers = $this->reader->headers(Storage::disk(self::DISK)->path($import->file_path), $sheet);

        if ($headers === []) {
            throw new ImportFileException(__('import.errors.empty_file'));
        }

        $validMapping = $this->validateMapping($mapping, $headers);

        $import->forceFill([
            'sheet' => $sheet,
            'mode' => $validOptions->mode,
            'options' => $validOptions->toArray(),
            'dry_run' => $validOptions->dryRun,
        ])->save();

        $this->memory->remember($import, $headers, $validMapping);

        return $import->refresh();
    }

    /**
     * Mark the import as being analyzed and queue the analysis (it runs inline with the sync queue driver).
     */
    public function startAnalysis(Import $import): Import
    {
        $import->forceFill([
            'status' => ImportStatus::Analyzing,
            'failure_message' => null,
            'last_activity_at' => now(),
        ])->save();

        AnalyzeImport::dispatch($import->id);

        return $import->refresh();
    }

    /**
     * Whether the mapping/options of the import may still be edited.
     */
    public function isEditable(Import $import): bool
    {
        return $import->status === ImportStatus::Uploaded
            || $import->status === ImportStatus::Analyzed
            || ($import->status === ImportStatus::Failed && $import->started_at === null);
    }

    /**
     * Back to the mapping step.
     */
    public function reopenMapping(Import $import): Import
    {
        if ($this->isEditable($import)) {
            $import->forceFill(['status' => ImportStatus::Uploaded, 'failure_message' => null, 'finished_at' => null])->save();
        }

        return $import->refresh();
    }

    // ------------------------------------------------------------------ internals

    private function assertStoredUpload(string $path): void
    {
        $valid = str_starts_with($path, self::UPLOAD_DIRECTORY.'/')
            && ! str_contains($path, '..')
            && Storage::disk(self::DISK)->exists($path);

        if (! $valid) {
            throw new ImportFileException(__('import_ui.upload_errors.unreadable'));
        }
    }

    /**
     * .xlsx must be a ZIP container, .csv must not be (a renamed archive or binary file is rejected).
     */
    private function assertSignature(string $absolute, string $extension): void
    {
        $handle = fopen($absolute, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 8);

        if ($handle !== false) {
            fclose($handle);
        }

        $isZip = str_starts_with($magic, "PK\x03\x04");
        $isOle = str_starts_with($magic, "\xD0\xCF\x11\xE0");

        if ($isOle) {
            throw new ImportFileException(__('import.errors.xls_unsupported'));
        }

        if (($extension === 'xlsx') !== $isZip) {
            throw new ImportFileException(__('import.errors.file_unreadable'));
        }
    }

    private function isZip(string $absolute): bool
    {
        $handle = fopen($absolute, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);

        if ($handle !== false) {
            fclose($handle);
        }

        return str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06");
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = mb_substr(trim($name), 0, 255);

        return $name === '' ? 'import' : $name;
    }

    private function humanSize(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',').' '.__('import_ui.mb');
    }
}
