<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog;

use App\Enums\AuditAction;
use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Models\AdminAuditEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only journal of admin actions: Система → Журнал действий (RB-12, `system.audit_log.view`).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'audit-log';

    protected static ?int $navigationSort = 110;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('roles.audit.title');
    }

    public static function getModelLabel(): string
    {
        return __('roles.audit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles.audit.title');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('roles.audit.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('roles.audit.columns.user'))
                    ->placeholder('—'),
                TextColumn::make('action')
                    ->label(__('roles.audit.columns.action'))
                    ->formatStateUsing(fn (string $state): string => AuditAction::tryFrom($state)?->label() ?? $state)
                    ->searchable(),
                TextColumn::make('subject')
                    ->label(__('roles.audit.columns.subject'))
                    ->state(fn (AdminAuditEntry $record): string => $record->subject_type === null ? '—' : $record->subject_type.' #'.$record->subject_id),
                TextColumn::make('data')
                    ->label(__('roles.audit.columns.data'))
                    ->state(fn (AdminAuditEntry $record): string => $record->data === null ? '—' : (string) json_encode($record->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->limit(120)
                    ->wrap(),
                TextColumn::make('ip')
                    ->label(__('roles.audit.columns.ip'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label(__('roles.audit.columns.action'))
                    ->options(AuditAction::options()),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('admin_audit_log.created_at')->orderByDesc('admin_audit_log.id'))
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLog::route('/'),
        ];
    }
}
