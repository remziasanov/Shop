<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Tables;

use App\Enums\PromoType;
use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Resources\PromoCodes\Support\PromoCodeSummary;
use App\Models\PromoCode;
use App\Models\PromoCodeBatch;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Promo\PromoCodeNormalizer;
use App\Services\Promo\PromoCodeSaver;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Promo code list (TZ 22.4): code, discount, scope, period, uses / limit, active, batch; filters; duplicate and deactivate
 * actions. Sorting by `usage_count` gives the PM-17 overview «by uses».
 */
final class PromoCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['targets', 'batch']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('promo_admin.codes.fields.code'))
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('promo_admin.codes.fields.name'))
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label(__('promo_admin.codes.table.discount'))
                    ->state(fn (PromoCode $record): string => PromoCodeSummary::discount($record))
                    ->description(fn (PromoCode $record): string => $record->type->label())
                    ->sortable(),
                TextColumn::make('scope')
                    ->label(__('promo_admin.codes.table.scope'))
                    ->state(fn (PromoCode $record): string => PromoCodeSummary::scope($record, app(CategoryTree::class)))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('period')
                    ->label(__('promo_admin.codes.table.period'))
                    ->state(fn (PromoCode $record): string => PromoCodeSummary::period($record))
                    ->color(fn (PromoCode $record): ?string => $record->ends_at !== null && $record->ends_at->isPast() ? 'danger' : null)
                    ->wrap(),
                TextColumn::make('usage_count')
                    ->label(__('promo_admin.codes.table.usage'))
                    ->state(fn (PromoCode $record): string => PromoCodeSummary::usage($record))
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label(__('promo_admin.codes.fields.is_active'))
                    ->disabled(fn (PromoCode $record): bool => ! Gate::allows('update', $record))
                    ->sortable(),
                TextColumn::make('batch.name')
                    ->label(__('promo_admin.codes.table.batch'))
                    ->placeholder(__('promo_admin.codes.table.no_batch'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('promo_admin.codes.filters.active')),
                SelectFilter::make('type')
                    ->label(__('promo_admin.codes.filters.type'))
                    ->options(PromoType::options()),
                SelectFilter::make('batch_id')
                    ->label(__('promo_admin.codes.filters.batch'))
                    ->options(fn (): array => PromoCodeBatch::query()->orderByDesc('id')->limit(200)->pluck('name', 'id')->all())
                    ->searchable(),
                Filter::make('expired')
                    ->label(__('promo_admin.codes.filters.expired'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('ends_at')->where('ends_at', '<', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::duplicateAction(),
                self::deactivateAction(),
                DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (PromoCode $record): string => PromoCodeResource::getUrl(PromoCodeResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]));
    }

    /**
     * 22.4 «дублировать»: a copy under a new code, same conditions, no uses.
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('promo_admin.codes.actions.duplicate'))
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('create', PromoCode::class))
            ->modalHeading(__('promo_admin.codes.actions.duplicate_heading'))
            ->schema([
                TextInput::make('code')
                    ->label(__('promo_admin.codes.actions.duplicate_code'))
                    ->required()
                    ->maxLength(PromoCodeNormalizer::MAX_LENGTH)
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! PromoCodeNormalizer::isValid((string) $value)) {
                            $fail(__('promo_admin.codes.validation.code_format'));
                        } elseif (app(PromoCodeSaver::class)->codeTaken((string) $value)) {
                            $fail(__('promo_admin.codes.validation.code_unique'));
                        }
                    }]),
            ])
            ->action(function (PromoCode $record, array $data): void {
                abort_unless(Gate::allows('create', PromoCode::class), 403);

                $user = Auth::user();
                $copy = app(PromoCodeSaver::class)->duplicate($record, (string) $data['code'], $user instanceof User ? $user : null);

                Notification::make()->success()->title(__('promo_admin.codes.actions.duplicate_done', ['code' => $copy->code]))->send();
            });
    }

    /**
     * 22.4 «деактивировать».
     */
    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label(__('promo_admin.codes.actions.deactivate'))
            ->icon('heroicon-o-no-symbol')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (PromoCode $record): bool => $record->is_active && Gate::allows('update', $record))
            ->action(function (PromoCode $record): void {
                abort_unless(Gate::allows('update', $record), 403);

                $record->forceFill(['is_active' => false])->save();

                Notification::make()->success()->title(__('promo_admin.codes.actions.deactivated', ['code' => $record->code]))->send();
            });
    }
}
