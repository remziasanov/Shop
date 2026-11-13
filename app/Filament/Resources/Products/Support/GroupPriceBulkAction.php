<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Enums\GroupPriceBulkOperation;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\User;
use App\Rules\PositiveAmount;
use App\Services\Pricing\GroupPriceBulkChanger;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Bulk action «Цены группы» of the product table (GP-18): the exact price of a customer group as «розница −X %» or
 * «закупка +X %», or deletion of the exact prices of the group, with a preview of the first 20 selected products and a
 * confirmation step. Needs `prices.bulk` (the same right as the base price change, RB-11).
 */
class GroupPriceBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'group_prices';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('admin_group_prices.bulk.label'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->visible(fn (): bool => Gate::allows('changePrices', Product::class))
            ->modalHeading(__('admin_group_prices.bulk.heading'))
            ->modalSubmitActionLabel(__('admin_group_prices.bulk.apply'))
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->steps([
                Step::make(__('admin_group_prices.bulk.step_params'))
                    ->schema([
                        Select::make('group_id')
                            ->label(__('admin_group_prices.bulk.group'))
                            ->options(fn (): array => CustomerGroup::query()->ordered()->pluck('name', 'id')->all())
                            ->required()
                            ->native(false),
                        Select::make('operation')
                            ->label(__('admin_group_prices.bulk.operation'))
                            ->options(GroupPriceBulkOperation::options())
                            ->default(GroupPriceBulkOperation::RetailMinusPercent->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        TextInput::make('value')
                            ->label(__('admin_group_prices.bulk.value'))
                            ->helperText(__('admin_group_prices.bulk.value_hint'))
                            ->suffix('%')
                            ->inputMode('decimal')
                            ->maxLength(20)
                            ->required(fn (Get $get): bool => self::operation($get('operation'))?->needsValue() === true)
                            ->visible(fn (Get $get): bool => self::operation($get('operation'))?->needsValue() === true)
                            ->rule(fn (Get $get): PositiveAmount => new PositiveAmount(4, self::operation($get('operation')) === GroupPriceBulkOperation::RetailMinusPercent ? '99.9999' : '1000')),
                    ]),
                Step::make(__('admin_group_prices.bulk.step_preview'))
                    ->schema([
                        View::make('filament.pricing.group-bulk-preview')
                            ->viewData(fn (Get $get, HasTable $livewire): array => self::previewData($livewire, $get('group_id'), (string) $get('operation'), $get('value'))),
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

                $group = CustomerGroup::query()->find((int) $data['group_id']);
                $actor = Auth::user();

                if ($group === null || ! $actor instanceof User) {
                    abort(403);
                }

                $operation = GroupPriceBulkOperation::from((string) $data['operation']);

                try {
                    $result = app(GroupPriceBulkChanger::class)->dispatch($ids, $group, $operation, $operation->needsValue() ? (string) $data['value'] : null, $actor);
                } catch (AuthorizationException) {
                    abort(403);
                } catch (InvalidArgumentException) {
                    Notification::make()->danger()->title(__('admin_pricing.preview.incomplete'))->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_group_prices.bulk.queued', ['count' => $result['products'], 'group' => $group->name]))->send();
            });
    }

    private static function operation(mixed $value): ?GroupPriceBulkOperation
    {
        return GroupPriceBulkOperation::tryFrom((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function previewData(HasTable $livewire, mixed $groupId, string $operation, mixed $value): array
    {
        $operation = GroupPriceBulkOperation::tryFrom($operation);
        $group = filled($groupId) ? CustomerGroup::query()->find((int) $groupId) : null;

        if ($operation === null || $group === null) {
            return ['empty' => __('admin_pricing.preview.incomplete')];
        }

        $query = $livewire->getSelectedTableRecordsQuery(false);
        $total = (clone $query)->count();

        try {
            $preview = app(GroupPriceBulkChanger::class)->preview(
                (clone $query)->toBase()->limit(GroupPriceBulkChanger::PREVIEW_LIMIT)->pluck($query->getModel()->getQualifiedKeyName())->all(),
                $group,
                $operation,
                $operation->needsValue() ? (string) $value : null,
            );
        } catch (InvalidArgumentException) {
            return ['empty' => __('admin_pricing.preview.incomplete')];
        }

        $format = static fn (?int $price): string => $price === null ? '—' : Money::format($price);

        return [
            'summary' => __('admin_group_prices.bulk.summary', ['count' => $total, 'shown' => count($preview['rows']), 'group' => $group->name]),
            'empty' => __('admin_pricing.preview.none'),
            'rows' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'sku' => $row['sku'],
                'before' => $format($row['before']),
                'after' => $format($row['after']),
                'note' => $row['skipped'] === null ? null : __('admin_group_prices.bulk.skipped.'.$row['skipped']),
            ], $preview['rows']),
        ];
    }
}
