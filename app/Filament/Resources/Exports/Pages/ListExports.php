<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exports\Pages;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Filament\Resources\Exports\ExportResource;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Export\CatalogExporter;
use App\Services\Export\ExportManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

class ListExports extends ListRecords
{
    protected static string $resource = ExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_export')
                ->label(__('export.resource.new'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->modalSubmitActionLabel(__('export.form.submit'))
                ->modalDescription(__('export.resource.retention', ['days' => config('shop.export_retention_days')]))
                ->schema([
                    Select::make('category_id')
                        ->label(__('export.form.category'))
                        ->helperText(__('export.form.category_help'))
                        ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                        ->placeholder(__('export.form.category_placeholder'))
                        ->searchable()
                        ->native(false),
                    Select::make('active')
                        ->label(__('export.form.active'))
                        ->options([
                            CatalogExporter::ACTIVE_ALL => __('export.form.active_all'),
                            CatalogExporter::ACTIVE_ONLY => __('export.form.active_active'),
                            CatalogExporter::INACTIVE_ONLY => __('export.form.active_inactive'),
                        ])
                        ->default(CatalogExporter::ACTIVE_ALL)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    self::start([
                        'category_id' => filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
                        'active' => (string) ($data['active'] ?? CatalogExporter::ACTIVE_ALL),
                    ], $user instanceof User ? $user : null);
                }),
        ];
    }

    /**
     * Start a catalog export and tell the user what happened.
     *
     * @param  array{category_id?: int|null, active?: string|null, product_ids?: list<int>|null}  $filters
     */
    public static function start(array $filters, ?User $user): void
    {
        try {
            $export = app(ExportManager::class)->request(ExportType::Catalog, $filters, $user);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('export.notifications.failed'))->send();

            return;
        }

        if ($export->status === ExportStatus::Completed) {
            Notification::make()->success()
                ->title(__('export.notifications.ready', ['rows' => $export->rows]))
                ->body(__('export.notifications.ready_body', ['days' => config('shop.export_retention_days')]))
                ->actions([
                    Action::make('download')
                        ->label(__('export.notifications.download'))
                        ->button()
                        ->url(route('admin.exports.download', $export), shouldOpenInNewTab: true),
                ])
                ->send();

            return;
        }

        Notification::make()->success()
            ->title(__('export.notifications.queued'))
            ->body(__('export.notifications.queued_body', ['rows' => app(CatalogExporter::class)->count($filters)]))
            ->send();
    }
}
