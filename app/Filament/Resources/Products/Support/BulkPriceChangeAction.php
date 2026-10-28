<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Enums\BulkPriceOperation;
use App\Models\Product;
use App\Models\User;
use App\Rules\PositiveAmount;
use App\Services\Pricing\BulkPriceChanger;
use App\Support\Money;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Bulk change of the base price by ±X % or ±N ₽ with a before/after preview of the first 20 selected
 * products and a confirmation step (AD-42, D-017). Administrators only (TZ 6.13).
 */
class BulkPriceChangeAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'change_price';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('admin_pricing.bulk.label'))
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->visible(fn (): bool => Gate::allows('changePrices', Product::class))
            ->modalHeading(__('admin_pricing.bulk.heading'))
            ->modalSubmitActionLabel(__('admin_pricing.bulk.apply'))
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->steps([
                Step::make(__('admin_pricing.bulk.step_params'))
                    ->schema([
                        TextInput::make('field')
                            ->label(__('admin_pricing.bulk.field'))
                            ->default(__('admin_pricing.bulk.field_base_price'))
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('operation')
                            ->label(__('admin_pricing.bulk.operation'))
                            ->options(BulkPriceOperation::options())
                            ->default(BulkPriceOperation::IncreasePercent->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        TextInput::make('value')
                            ->label(__('admin_pricing.bulk.value'))
                            ->helperText(fn (Get $get): string => BulkPriceOperation::tryFrom((string) $get('operation'))?->isPercent() === false
                                ? __('admin_pricing.rules.fields.value_hint_rubles')
                                : __('admin_pricing.rules.fields.value_hint_percent'))
                            ->suffix(fn (Get $get): string => BulkPriceOperation::tryFrom((string) $get('operation'))?->isPercent() === false ? Money::CURRENCY_SIGN : '%')
                            ->inputMode('decimal')
                            ->required()
                            ->maxLength(20)
                            ->rule(fn (Get $get): PositiveAmount => new PositiveAmount(
                                BulkPriceOperation::tryFrom((string) $get('operation'))?->isPercent() === false ? 2 : 4,
                            )),
                    ]),
                Step::make(__('admin_pricing.bulk.step_preview'))
                    ->schema([
                        View::make('filament.pricing.preview-table')
                            ->viewData(fn (Get $get, HasTable $livewire): array => self::previewData($livewire, (string) $get('operation'), (string) $get('value'))),
                    ]),
            ])
            ->action(function (HasTable $livewire, array $data): void {
                abort_unless(Gate::allows('changePrices', Product::class), 403);

                $ids = $livewire->getSelectedTableRecords(false)->all();

                $max = (int) config('shop.bulk_price_max_selection');

                if (count($ids) > $max) {
                    Notification::make()->danger()
                        ->title(__('admin_pricing.bulk.too_many', ['max' => number_format($max, 0, '', ' ')]))
                        ->send();

                    return;
                }

                $actor = Auth::user();

                $result = app(BulkPriceChanger::class)->dispatch(
                    $ids,
                    BulkPriceOperation::from((string) $data['operation']),
                    (string) $data['value'],
                    $actor instanceof User ? $actor : abort(403),
                );

                Notification::make()->success()->title(__('admin_pricing.bulk.queued', ['count' => $result['products']]))->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function previewData(HasTable $livewire, string $operation, string $value): array
    {
        $operation = BulkPriceOperation::tryFrom($operation);

        if ($operation === null) {
            return ['empty' => __('admin_pricing.preview.incomplete')];
        }

        $query = $livewire->getSelectedTableRecordsQuery(false);
        $total = (clone $query)->count();

        try {
            $rows = app(BulkPriceChanger::class)->preview(
                (clone $query)->toBase()->limit(BulkPriceChanger::PREVIEW_LIMIT)->pluck($query->getModel()->getQualifiedKeyName())->all(),
                $operation,
                $value,
            );
        } catch (InvalidArgumentException) {
            return ['empty' => __('admin_pricing.preview.incomplete')];
        }

        return [
            'summary' => __('admin_pricing.bulk.summary', ['count' => $total, 'shown' => count($rows)]),
            'empty' => __('admin_pricing.preview.none'),
            'rows' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'sku' => $row['sku'],
                'before' => Money::format($row['before']),
                'after' => Money::format($row['after']),
            ], $rows),
        ];
    }
}
