<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportField;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Exceptions\ImportFileException;
use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use App\Models\User;
use App\Services\Import\ColumnMapper;
use App\Services\Import\ImportAnalyzer;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportWizard;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Import wizard (IM-01…IM-06). One page, three steps derived from the status of the import record:
 * uploaded → «Сопоставление», analyzing/analyzed → «Проверка»; started imports continue on the view page.
 * Everything the client sends is re-validated on the server ({@see ImportWizard}).
 *
 * @property-read Schema $form
 */
class CreateImport extends Page
{
    protected static string $resource = ImportResource::class;

    protected string $view = 'filament.resources.imports.wizard';

    /** Seconds after which «Проверить сейчас» is offered when the queue does not analyze the file. */
    public const ANALYZE_FALLBACK_SECONDS = 30;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?int $importId = null;

    public ?string $sheet = null;

    /** @var list<string> */
    public array $sheetNames = [];

    /** @var list<string> */
    public array $headers = [];

    /** @var array<int, list<string>> */
    public array $samples = [];

    /** @var array<int, string> */
    public array $mapping = [];

    /** @var list<string> */
    public array $mappingWarnings = [];

    /** @var array<string, mixed> */
    public array $options = [];

    public bool $confirmFullSync = false;

    #[Locked]
    public ?int $fullSyncCount = null;

    public ?string $columnsError = null;

    public function getTitle(): string
    {
        return __('import_ui.wizard.title');
    }

    public function mount(): void
    {
        abort_unless(ImportResource::canCreate(), 403);

        $this->options = ImportOptions::fromArray(null)->toArray();
        $this->form->fill(['media_source' => MediaSource::None->value]);

        $id = request()->query('import');
        $import = is_numeric($id) ? Import::query()->find((int) $id) : null;

        if ($import !== null && Gate::allows('view', $import)) {
            $this->importId = $import->id;
            $this->enterStep($import);
        }
    }

    public function form(Schema $schema): Schema
    {
        $wizard = app(ImportWizard::class);
        $maxKb = max(1, intdiv($wizard->maxUploadBytes(), 1024));

        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file')
                    ->label(__('import_ui.file.file'))
                    ->helperText(__('import_ui.file.file_help').' '.$wizard->limitsHint())
                    ->disk(ImportWizard::DISK)
                    ->directory(ImportWizard::UPLOAD_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(ImportWizard::FILE_MIMES)
                    ->maxSize($maxKb)
                    ->storeFileNamesIn('original_name')
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::random(40).'.'.strtolower($file->getClientOriginalExtension()))
                    ->rules([
                        fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value instanceof UploadedFile && ! ImportWizard::hasAcceptedExtension($value->getClientOriginalName())) {
                                $fail(__('import_ui.upload_errors.extension'));
                            }
                        },
                    ])
                    ->required()
                    ->validationMessages(['required' => __('import_ui.upload_errors.required')]),
                Hidden::make('original_name'),
                Radio::make('media_source')
                    ->label(__('import_ui.file.photos_source'))
                    ->helperText(__('import_ui.file.photos_source_help'))
                    ->options([
                        MediaSource::None->value => __('import_ui.file.source_none'),
                        MediaSource::Zip->value => __('import_ui.file.source_zip'),
                        MediaSource::ServerDir->value => __('import_ui.file.source_server_dir'),
                    ])
                    ->default(MediaSource::None->value)
                    ->live()
                    ->required(),
                FileUpload::make('media_zip')
                    ->label(__('import_ui.file.zip'))
                    ->helperText(__('import_ui.file.zip_help').' '.$wizard->limitsHint())
                    ->disk(ImportWizard::DISK)
                    ->directory(ImportWizard::UPLOAD_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(ImportWizard::ZIP_MIMES)
                    ->maxSize($maxKb)
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::random(40).'.zip')
                    ->rules([
                        fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if ($value instanceof UploadedFile && ! ImportWizard::isZipName($value->getClientOriginalName())) {
                                $fail(__('import_ui.upload_errors.zip_extension'));
                            }
                        },
                    ])
                    ->visible(fn (Get $get): bool => $get('media_source') === MediaSource::Zip->value)
                    ->required(fn (Get $get): bool => $get('media_source') === MediaSource::Zip->value),
                Select::make('media_server_entry')
                    ->label(__('import_ui.file.server_entry'))
                    ->helperText(fn (): string => __('import_ui.file.server_entry_help').($wizard->serverMediaEntries() === [] ? ' '.__('import_ui.file.server_entry_empty') : ''))
                    ->options(fn (): array => $wizard->serverMediaEntries())
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('media_source') === MediaSource::ServerDir->value)
                    ->required(fn (Get $get): bool => $get('media_source') === MediaSource::ServerDir->value),
            ]);
    }

    // ------------------------------------------------------------------ state

    public function currentImport(): ?Import
    {
        if ($this->importId === null) {
            return null;
        }

        $import = Import::query()->find($this->importId);

        if ($import === null || Gate::denies('view', $import)) {
            return null;
        }

        return $import;
    }

    /**
     * 'file' | 'mapping' | 'check'
     */
    public function step(): string
    {
        $import = $this->currentImport();

        if ($import === null) {
            return 'file';
        }

        return $import->status === ImportStatus::Uploaded ? 'mapping' : 'check';
    }

    /**
     * @return array<string, string>
     */
    public function targetOptions(): array
    {
        return app(ImportWizard::class)->targetOptions($this->headers, $this->mapping);
    }

    /**
     * Warnings for duplicated targets of the current (unsaved) mapping.
     *
     * @return list<string>
     */
    public function liveMappingWarnings(): array
    {
        $mapper = app(ColumnMapper::class);
        $input = [];

        foreach ($this->headers as $index => $_) {
            $input[$index] = ($this->mapping[$index] ?? '') === '' ? null : $this->mapping[$index];
        }

        return $mapper->dedupe($input, $this->headers)->warnings($this->headers);
    }

    public function canUseFullSync(): bool
    {
        return Gate::allows('useFullSync', Import::class);
    }

    /**
     * @return array<string, string>
     */
    public function modeOptions(): array
    {
        $options = ImportMode::options();

        if (! $this->canUseFullSync()) {
            unset($options[ImportMode::FullSync->value]);
        }

        return $options;
    }

    public function fullSyncAffected(Import $import): int
    {
        return $this->fullSyncCount ??= app(ImportRunner::class)->fullSyncAffectedCount($import);
    }

    /**
     * Columns of the preview table: mapped catalog fields in file order, characteristics in one column.
     *
     * @return list<array{field: string, label: string}>
     */
    public function previewColumns(Import $import): array
    {
        $headers = $import->analysis['headers'] ?? [];
        $columns = [];
        $hasAttributes = false;

        foreach ($import->mapping ?? [] as $index => $target) {
            if ($target === null || $target === '') {
                continue;
            }

            if (ImportField::attributeName($target) !== null) {
                $hasAttributes = true;

                continue;
            }

            $header = $headers[$index] ?? null;
            $columns[] = [
                'field' => $target,
                'label' => is_string($header) && $header !== '' ? $header : (ImportField::tryFrom($target)?->label() ?? $target),
            ];
        }

        if ($hasAttributes) {
            $columns[] = ['field' => 'attributes', 'label' => __('import_ui.check.attributes')];
        }

        return $columns;
    }

    /**
     * Text of one preview cell: the normalized value, or the raw cell text when the value was rejected.
     *
     * @param  array<string, mixed>  $row
     */
    public function formatCell(string $field, array $row): string
    {
        if ($field === 'attributes') {
            $parts = [];

            foreach ($row['attributes'] ?? [] as $attribute) {
                if (is_array($attribute)) {
                    $parts[] = trim((string) ($attribute['name'] ?? '').': '.(string) ($attribute['value'] ?? ''), ': ');
                }
            }

            return implode('; ', $parts);
        }

        $value = $row['values'][$field] ?? $row['raw'][$field] ?? null;

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? __('export.yes') : __('export.no'),
            is_array($value) => implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    public function analysisIsStuck(Import $import): bool
    {
        return $import->status === ImportStatus::Analyzing
            && $import->last_activity_at !== null
            && $import->last_activity_at->lte(now()->subSeconds(self::ANALYZE_FALLBACK_SECONDS));
    }

    // ------------------------------------------------------------------ step 1: file

    public function uploadFile(): void
    {
        abort_unless(ImportResource::canCreate(), 403);

        $state = $this->form->getState();

        $file = $this->firstValue($state['file'] ?? null);
        $source = MediaSource::tryFrom((string) ($state['media_source'] ?? '')) ?? MediaSource::None;

        if ($file === null) {
            throw ValidationException::withMessages(['data.file' => __('import_ui.upload_errors.required')]);
        }

        $name = $this->firstValue($state['original_name'] ?? $this->data['original_name'] ?? null) ?? basename($file);
        $user = Auth::user();

        try {
            $import = app(ImportWizard::class)->createImport(
                $user instanceof User ? $user : abort(403),
                $file,
                $name,
                $source,
                $source === MediaSource::Zip ? $this->firstValue($state['media_zip'] ?? null) : null,
                $source === MediaSource::ServerDir ? $this->firstValue($state['media_server_entry'] ?? null) : null,
            );
        } catch (ImportFileException $e) {
            $this->addError('data.file', $e->getMessage());

            return;
        }

        $this->importId = $import->id;
        $this->enterStep($import);
        $this->js("history.replaceState(null, '', '".ImportResource::getUrl('create').'?import='.$import->id."')");
    }

    // ------------------------------------------------------------------ step 2: mapping

    public function updatedSheet(): void
    {
        $import = $this->currentImport();

        if ($import === null || $import->status !== ImportStatus::Uploaded) {
            return;
        }

        $this->loadColumns($import, $this->sheet, false);
    }

    public function updatedMapping(): void
    {
        // The warnings of the automatic mapping are replaced by the live ones as soon as the user edits it.
        $this->mappingWarnings = [];
        $this->resetErrorBag('mapping');
    }

    public function saveMapping(): void
    {
        $this->resetErrorBag();

        $import = $this->currentImport() ?? abort(404);
        $user = Auth::user();

        if ($import->status !== ImportStatus::Uploaded) {
            $this->notifyWrongState();

            return;
        }

        try {
            $wizard = app(ImportWizard::class);
            $import = $wizard->saveMapping($import, $user instanceof User ? $user : abort(403), $this->sheet, $this->mapping, $this->options);
            $wizard->startAnalysis($import);
        } catch (ImportFileException $e) {
            $this->addError('mapping', $e->getMessage());

            return;
        }

        $this->fullSyncCount = null;
        $this->confirmFullSync = false;
    }

    // ------------------------------------------------------------------ step 3: check

    public function backToMapping(): void
    {
        $import = $this->currentImport() ?? abort(404);

        if (! app(ImportWizard::class)->isEditable($import)) {
            $this->notifyWrongState();

            return;
        }

        $import = app(ImportWizard::class)->reopenMapping($import);
        $this->fullSyncCount = null;
        $this->enterStep($import);
    }

    /**
     * Queue does not run the analysis (no cron): do it in this request.
     */
    public function analyzeNow(): void
    {
        $import = $this->currentImport() ?? abort(404);

        if (! $this->analysisIsStuck($import)) {
            return;
        }

        try {
            app(ImportAnalyzer::class)->analyze($import);
        } catch (ImportFileException) {
            // Stored on the import as a user-facing message.
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function startImport(): void
    {
        abort_unless(ImportResource::canCreate(), 403);

        $this->resetErrorBag();

        $import = $this->currentImport() ?? abort(404);

        if ($import->status !== ImportStatus::Analyzed) {
            $this->notifyWrongState();

            return;
        }

        if ($import->mode === ImportMode::FullSync) {
            if (Gate::denies('useFullSync', Import::class)) {
                Notification::make()->danger()->title(__('import_ui.mapping.full_sync_forbidden'))->send();

                return;
            }

            if (! $this->confirmFullSync) {
                $this->addError('confirmFullSync', __('import_ui.check.full_sync_required'));

                return;
            }
        }

        try {
            app(ImportRunner::class)->start($import);
        } catch (\LogicException) {
            $this->notifyWrongState();

            return;
        }

        $this->redirect(ImportResource::getUrl('view', ['record' => $import]), navigate: false);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Prepare the component for the step the import is in.
     */
    private function enterStep(Import $import): void
    {
        if (($import->status->isFinished() && $import->started_at !== null) || in_array($import->status, [ImportStatus::Queued, ImportStatus::Processing], true)) {
            $this->redirect(ImportResource::getUrl('view', ['record' => $import]));

            return;
        }

        $this->options = $import->importOptions()->toArray();

        if (! $this->canUseFullSync() && $import->importOptions()->mode === ImportMode::FullSync) {
            $this->options['mode'] = ImportMode::CreateUpdate->value;
        }

        if ($import->status === ImportStatus::Uploaded) {
            try {
                $this->sheetNames = app(ImportWizard::class)->sheets($import);
            } catch (ImportFileException $e) {
                $this->sheetNames = [];
                $this->columnsError = $e->getMessage();

                return;
            }

            $this->loadColumns($import, $import->sheet, true);
        }
    }

    private function loadColumns(Import $import, ?string $sheet, bool $useSavedMapping): void
    {
        $this->columnsError = null;

        try {
            $columns = app(ImportWizard::class)->columns($import, $sheet, 3, $useSavedMapping);
        } catch (ImportFileException $e) {
            $this->headers = [];
            $this->samples = [];
            $this->mapping = [];
            $this->mappingWarnings = [];
            $this->columnsError = $e->getMessage();

            return;
        }

        $this->sheet = $sheet;
        $this->headers = $columns['headers'];
        $this->samples = $columns['samples'];
        $this->mapping = $columns['mapping'];
        $this->mappingWarnings = $columns['warnings'];
    }

    private function notifyWrongState(): void
    {
        Notification::make()->warning()->title(__('import_ui.notifications.wrong_state'))->send();
    }

    private function firstValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
