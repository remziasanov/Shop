<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Enums\ImportIssueLevel;
use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Services\Import\ImportProgress;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Livewire\WithPagination;

/**
 * Progress page and report of one import (IM-09, IM-10, IM-12). While the import runs the page polls every 2 s;
 * when the queue does not move it for 60 s, the browser fallback processes the chunks through an AJAX endpoint.
 */
class ViewImport extends Page
{
    use InteractsWithRecord;
    use WithPagination;

    public const ISSUES_PER_PAGE = 25;

    protected static string $resource = ImportResource::class;

    protected string $view = 'filament.resources.imports.view';

    /** all | error | warning */
    public string $levelFilter = 'all';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string
    {
        return __('import_ui.resource.singular').' #'.$this->importRecord()->id;
    }

    public function updatingLevelFilter(): void
    {
        $this->resetPage('issuesPage');
    }

    /**
     * The import as it is in the database right now (the page polls).
     */
    public function importRecord(): Import
    {
        /** @var Import $import */
        $import = Import::query()->with('user:id,name')->findOrFail($this->getRecord()->getKey());

        return $import;
    }

    public function progress(): ImportProgress
    {
        return app(ImportProgress::class);
    }

    public function sourceFileExists(Import $import): bool
    {
        return $import->file_path !== '' && Storage::disk('local')->exists($import->file_path);
    }

    /**
     * @return LengthAwarePaginator<int, ImportIssue>
     */
    public function issues(Import $import): LengthAwarePaginator
    {
        return $import->issues()
            ->when($this->levelFilter === 'error', fn ($query) => $query->where('level', ImportIssueLevel::Error->value))
            ->when($this->levelFilter === 'warning', fn ($query) => $query->where('level', ImportIssueLevel::Warning->value))
            ->orderBy('row_number')
            ->orderBy('id')
            ->paginate(self::ISSUES_PER_PAGE, pageName: 'issuesPage');
    }

    protected function getHeaderActions(): array
    {
        $import = $this->importRecord();

        return [
            Action::make('download_errors')
                ->label(__('import_ui.report.download_errors'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(route('admin.imports.download', ['import' => $import, 'kind' => 'errors']))
                ->visible($import->status->isFinished() && $import->error_count > 0 && $this->sourceFileExists($import)),
            Action::make('download_source')
                ->label(__('import_ui.report.download_source'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(route('admin.imports.download', ['import' => $import, 'kind' => 'source']))
                ->visible($this->sourceFileExists($import)),
            Action::make('new_import')
                ->label(__('import_ui.report.new_import'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(ImportResource::getUrl('create'))
                ->visible(ImportResource::canCreate()),
        ];
    }
}
