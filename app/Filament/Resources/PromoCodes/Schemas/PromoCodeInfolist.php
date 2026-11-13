<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PromoCodes\Support\PromoCodeSummary;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Promo\PromoCodeStats;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

/**
 * Promo code card: the conditions and the «Статистика» block of PM-17 (uses, discount total, orders, revenue, average
 * check, per day, last 20 orders with links).
 */
final class PromoCodeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('promo_admin.codes.sections.main'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('code')->label(__('promo_admin.codes.fields.code'))->weight('bold')->copyable(),
                        TextEntry::make('name')->label(__('promo_admin.codes.fields.name')),
                        TextEntry::make('is_active')
                            ->label(__('promo_admin.codes.fields.is_active'))
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                            ->formatStateUsing(fn (bool $state): string => $state ? __('promo_admin.codes.summary.yes') : __('promo_admin.codes.summary.no')),
                        TextEntry::make('discount')
                            ->label(__('promo_admin.codes.table.discount'))
                            ->state(fn (PromoCode $record): string => PromoCodeSummary::discount($record)),
                        TextEntry::make('scope')
                            ->label(__('promo_admin.codes.table.scope'))
                            ->state(fn (PromoCode $record): string => PromoCodeSummary::scope($record, app(CategoryTree::class))),
                        TextEntry::make('period')
                            ->label(__('promo_admin.codes.table.period'))
                            ->state(fn (PromoCode $record): string => PromoCodeSummary::period($record)),
                        TextEntry::make('usage')
                            ->label(__('promo_admin.codes.table.usage'))
                            ->state(fn (PromoCode $record): string => PromoCodeSummary::usage($record)),
                        TextEntry::make('per_customer_limit')
                            ->label(__('promo_admin.codes.fields.per_customer_limit'))
                            ->placeholder(__('promo_admin.codes.summary.unlimited')),
                        TextEntry::make('min_order_amount')
                            ->label(__('promo_admin.codes.fields.min_order_amount'))
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Money::format(Money::fromRubles($state))),
                        TextEntry::make('groups')
                            ->label(__('promo_admin.codes.view.groups'))
                            ->state(fn (PromoCode $record): string => $record->groups()->orderBy('customer_groups.id')->pluck('name')->implode(', ')),
                        TextEntry::make('batch')
                            ->label(__('promo_admin.codes.table.batch'))
                            ->state(fn (PromoCode $record): string => $record->batch->name ?? '—'),
                        TextEntry::make('creator')
                            ->label(__('promo_admin.codes.view.created_by'))
                            ->state(fn (PromoCode $record): string => (string) ($record->created_by !== null ? User::query()->whereKey($record->created_by)->value('name') : '—')),
                    ]),

                Section::make(__('promo_admin.codes.sections.stats'))
                    ->schema([
                        View::make('filament.promo.code-stats')
                            ->viewData(fn (PromoCode $record): array => self::statsData($record)),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function statsData(PromoCode $code): array
    {
        $stats = app(PromoCodeStats::class);
        $summary = $stats->summary($code);

        return [
            'summary' => [
                'uses' => $summary['uses'],
                'discount' => Money::format($summary['discount']),
                'orders' => $summary['orders'],
                'revenue' => Money::format($summary['revenue']),
                'average' => Money::format($summary['average_check']),
            ],
            'days' => array_map(fn (array $day): array => [
                'day' => date('d.m.Y', (int) strtotime($day['day'])),
                'uses' => $day['uses'],
                'discount' => Money::format($day['discount']),
                'orders' => $day['orders'],
                'revenue' => Money::format($day['revenue']),
            ], array_reverse($stats->perDay($code))),
            'orders' => $stats->recentOrders($code)->map(fn (Order $order): array => [
                'number' => $order->number,
                'url' => Gate::allows('view', $order) ? OrderResource::getUrl('view', ['record' => $order]) : null,
                'date' => $order->created_at?->format('d.m.Y H:i'),
                'total' => Money::format(Money::fromRubles($order->total ?? '0')),
                'status' => $order->status->label($order->type),
            ])->all(),
        ];
    }
}
