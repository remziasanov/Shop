<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Schemas;

use App\Filament\Resources\PromoLinks\Support\PromoLinkSummary;
use App\Models\PromoLink;
use App\Services\Promo\PromoLinkSaver;
use App\Services\Promo\PromoLinkStats;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Promo link card: address, check and preview of the destination, the code that will be applied (PM-26) and the
 * statistics (PM-25: clicks, unique, orders, revenue, conversion, per day).
 */
final class PromoLinkInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('promo_admin.links.sections.main'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('name')->label(__('promo_admin.links.fields.name'))->weight('bold'),
                        TextEntry::make('is_active')
                            ->label(__('promo_admin.links.fields.is_active'))
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                            ->formatStateUsing(fn (bool $state): string => $state ? __('promo_admin.codes.summary.yes') : __('promo_admin.codes.summary.no')),
                        TextEntry::make('public_url')
                            ->label(__('promo_admin.links.fields.link'))
                            ->state(fn (PromoLink $record): string => app(PromoLinkSaver::class)->publicUrl($record->slug))
                            ->copyable(),
                        TextEntry::make('target')
                            ->label(__('promo_admin.links.table.target'))
                            ->state(fn (PromoLink $record): string => PromoLinkSummary::target($record)),
                        TextEntry::make('period')
                            ->label(__('promo_admin.links.table.period'))
                            ->state(fn (PromoLink $record): string => PromoLinkSummary::period($record)),
                    ]),

                Section::make(__('promo_admin.links.sections.check'))
                    ->schema([
                        TextEntry::make('preview_url')
                            ->label(__('promo_admin.links.fields.preview_url'))
                            ->state(function (PromoLink $record): string {
                                $resolution = app(PromoLinkSaver::class)->check($record);

                                return $resolution->ok ? (string) $resolution->url : __('promo_admin.links.actions.check_failed', ['reason' => (string) $resolution->reasonText()]);
                            })
                            ->color(fn (PromoLink $record): ?string => app(PromoLinkSaver::class)->check($record)->ok ? null : 'danger'),
                        TextEntry::make('code_status')
                            ->label(__('promo_admin.links.sections.code'))
                            ->state(fn (PromoLink $record): string => app(PromoLinkSaver::class)->codeStatus($record)),
                    ]),

                Section::make(__('promo_admin.links.sections.stats'))
                    ->schema([
                        View::make('filament.promo.link-stats')
                            ->viewData(fn (PromoLink $record): array => self::statsData($record)),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function statsData(PromoLink $link): array
    {
        $stats = app(PromoLinkStats::class);
        $summary = $stats->summary($link);

        return [
            'summary' => [
                'clicks' => $summary['clicks'],
                'unique' => $summary['unique_clicks'],
                'orders' => $summary['orders'],
                'revenue' => Money::format($summary['revenue']),
                'conversion' => $summary['conversion'] === null ? __('promo_admin.links.stats.conversion_none') : str_replace('.', ',', (string) $summary['conversion']).' %',
            ],
            'days' => array_map(fn (array $day): array => [
                'day' => date('d.m.Y', (int) strtotime($day['day'])),
                'clicks' => $day['clicks'],
                'unique' => $day['unique_clicks'],
                'orders' => $day['orders'],
                'revenue' => Money::format($day['revenue']),
            ], array_reverse($stats->perDay($link))),
        ];
    }
}
