<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Filament\Resources\Exports\Pages\ListExports;
use App\Models\Export;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * «Экспорт в Excel» for the selected products (AD-42, D-017): the file has the import template format.
 * Up to `shop.export_sync_limit` products are exported at once, more go to the queue (EX-03).
 */
class ExportSelectedAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'export_excel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('export.bulk.label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading(__('export.bulk.heading'))
            ->modalDescription(__('export.bulk.description'))
            ->visible(fn (): bool => Gate::allows('create', Export::class))
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->action(function (HasTable $livewire): void {
                abort_unless(Gate::allows('create', Export::class), 403);

                $ids = array_map('intval', $livewire->getSelectedTableRecords(false)->all());
                sort($ids);
                $max = (int) config('shop.export_max_selection');

                if (count($ids) > $max) {
                    Notification::make()->danger()
                        ->title(__('export.bulk.too_many', ['max' => number_format($max, 0, '', ' ')]))
                        ->send();

                    return;
                }

                $user = Auth::user();

                ListExports::start(['product_ids' => $ids], $user instanceof User ? $user : null);
            });
    }
}
