<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Models\Import;
use App\Services\Import\ImportProgress;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Import journal and the «Импорт» entry of the «Каталог» group (IM-13, AD-03).
 *
 * Administrators and managers share one catalog, so both see every import (not only their own); only
 * administrators may delete one. Files of an import are kept for 30 days.
 */
class ImportResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $slug = 'imports';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('import_ui.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('import_ui.resource.plural');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user:id,name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('import_ui.table.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('import_ui.table.user'))
                    ->placeholder('—'),
                TextColumn::make('original_name')
                    ->label(__('import_ui.table.file'))
                    ->limit(40)
                    ->tooltip(fn (Import $record): string => $record->original_name)
                    ->searchable(),
                TextColumn::make('mode')
                    ->label(__('import_ui.table.mode'))
                    ->formatStateUsing(fn (ImportMode|string $state, Import $record): string => ($state instanceof ImportMode ? $state : ImportMode::from($state))->label()
                        .($record->dry_run ? ' ('.__('import_ui.table.dry_run').')' : '')),
                TextColumn::make('status')
                    ->label(__('import_ui.table.status'))
                    ->badge()
                    ->color(fn (ImportStatus|string $state): string => match ($state instanceof ImportStatus ? $state : ImportStatus::from($state)) {
                        ImportStatus::Completed => 'success',
                        ImportStatus::CompletedWithErrors => 'warning',
                        ImportStatus::Failed => 'danger',
                        ImportStatus::Queued, ImportStatus::Processing, ImportStatus::Analyzing => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (ImportStatus|string $state): string => ($state instanceof ImportStatus ? $state : ImportStatus::from($state))->label())
                    ->tooltip(fn (Import $record): ?string => $record->failure_message),
                TextColumn::make('created_count')->label(__('import_ui.table.created'))->numeric(),
                TextColumn::make('updated_count')->label(__('import_ui.table.updated'))->numeric(),
                TextColumn::make('unchanged_count')->label(__('import_ui.table.unchanged'))->numeric(),
                TextColumn::make('skipped_count')->label(__('import_ui.table.skipped'))->numeric(),
                TextColumn::make('error_count')
                    ->label(__('import_ui.table.errors'))
                    ->numeric()
                    ->color(fn (Import $record): ?string => $record->error_count > 0 ? 'danger' : null),
                TextColumn::make('warning_count')->label(__('import_ui.table.warnings'))->numeric(),
                TextColumn::make('duration')
                    ->label(__('import_ui.table.duration'))
                    ->state(fn (Import $record): string => app(ImportProgress::class)->durationLabel($record)),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('imports.created_at')->orderByDesc('imports.id'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('import_ui.table.status'))
                    ->options(ImportStatus::options()),
                SelectFilter::make('mode')
                    ->label(__('import_ui.table.mode'))
                    ->options(ImportMode::options()),
                SelectFilter::make('user_id')
                    ->label(__('import_ui.table.user'))
                    ->relationship('user', 'name'),
            ])
            ->recordUrl(fn (Import $record): string => static::getUrl('view', ['record' => $record]))
            ->poll('10s')
            ->emptyStateHeading(__('import_ui.table.empty_heading'))
            ->recordActions([
                Action::make('open')
                    ->label(__('import_ui.resource.open'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Import $record): string => static::getUrl('view', ['record' => $record])),
                DeleteAction::make()
                    ->label(__('import_ui.resource.delete')),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImports::route('/'),
            'create' => CreateImport::route('/create'),
            'view' => ViewImport::route('/{record}'),
        ];
    }
}
