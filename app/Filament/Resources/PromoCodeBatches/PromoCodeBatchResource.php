<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeBatches;

use App\Enums\PromoBatchStatus;
use App\Filament\Resources\PromoCodeBatches\Pages\ListPromoCodeBatches;
use App\Filament\Resources\PromoCodeBatches\Pages\ViewPromoCodeBatch;
use App\Filament\Resources\PromoCodeBatches\Support\PromoBatchActions;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\PromoCodeBatch;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Batches of generated codes (PM-15, PM-16): progress and status, template change, export. Needs `promo_codes.generate`.
 * Batches are created by the «Сгенерировать пакет» action, never by a form.
 */
class PromoCodeBatchResource extends Resource
{
    protected static ?string $model = PromoCodeBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'promo-code-batches';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('promo_admin.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('promo_admin.batches.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promo_admin.batches.plural');
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('promo_admin.batches.view.main'))
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('name')->label(__('promo_admin.batches.fields.name'))->weight('bold'),
                    TextEntry::make('prefix')->label(__('promo_admin.batches.fields.prefix'))->placeholder('—'),
                    TextEntry::make('status')
                        ->label(__('promo_admin.batches.fields.status'))
                        ->badge()
                        ->color(fn (PromoBatchStatus $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (PromoBatchStatus $state): string => $state->label()),
                    TextEntry::make('progress')
                        ->label(__('promo_admin.batches.fields.progress'))
                        ->state(fn (PromoCodeBatch $record): string => self::progress($record)),
                    TextEntry::make('code_length')->label(__('promo_admin.batches.fields.code_length')),
                    TextEntry::make('created_at')->label(__('promo_admin.batches.fields.created_at'))->dateTime('d.m.Y H:i'),
                    TextEntry::make('creator')
                        ->label(__('promo_admin.batches.fields.created_by'))
                        ->state(fn (PromoCodeBatch $record): string => (string) ($record->created_by !== null ? User::query()->whereKey($record->created_by)->value('name') : '—')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('promo_admin.batches.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('prefix')
                    ->label(__('promo_admin.batches.fields.prefix'))
                    ->placeholder('—'),
                TextColumn::make('progress')
                    ->label(__('promo_admin.batches.fields.progress'))
                    ->state(fn (PromoCodeBatch $record): string => self::progress($record)),
                TextColumn::make('status')
                    ->label(__('promo_admin.batches.fields.status'))
                    ->badge()
                    ->color(fn (PromoBatchStatus $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (PromoBatchStatus $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('promo_admin.batches.fields.created_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('codes')
                    ->label(__('promo_admin.batches.actions.codes'))
                    ->icon('heroicon-o-ticket')
                    ->color('gray')
                    ->visible(fn (): bool => PromoCodeResource::canViewAny())
                    ->url(fn (PromoCodeBatch $record): string => PromoCodeResource::getUrl('index', ['filters' => ['batch_id' => ['value' => $record->id]]])),
                PromoBatchActions::applyTemplate(),
                PromoBatchActions::export(),
            ])
            // The progress of a running generation (PM-15).
            ->poll('10s')
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (PromoCodeBatch $record): string => self::getUrl('view', ['record' => $record]));
    }

    public static function progress(PromoCodeBatch $batch): string
    {
        return __('promo_admin.batches.progress', ['generated' => $batch->generated, 'count' => $batch->count]);
    }

    public static function statusColor(PromoBatchStatus $status): string
    {
        return match ($status) {
            PromoBatchStatus::Done => 'success',
            PromoBatchStatus::Running => 'info',
            PromoBatchStatus::Failed => 'danger',
            PromoBatchStatus::Queued => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodeBatches::route('/'),
            'view' => ViewPromoCodeBatch::route('/{record}'),
        ];
    }
}
