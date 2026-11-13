<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Resources\PromoLinks\PromoLinkResource;
use App\Services\Promo\PromoCodeStats;
use App\Services\Promo\PromoLinkStats;
use App\Services\Users\PermissionService;
use App\Support\Money;
use Filament\Widgets\Widget;

/**
 * 22.4: the top promo codes (and, with `promo_links.view`, links) of the last 30 days. Visible with `promo_codes.view`.
 */
class PromoTopWidget extends Widget
{
    public const DAYS = 30;

    protected static ?int $sort = 5;

    protected string $view = 'filament.widgets.promo-top';

    public static function canView(): bool
    {
        return app(PermissionService::class)->allowsCurrent('promo_codes.view');
    }

    /**
     * @return list<array{label: string, url: ?string, uses: int, discount: string}>
     */
    public function codes(): array
    {
        $canOpen = PromoCodeResource::canViewAny();

        return array_map(fn (array $row): array => [
            'label' => $row['code']->code,
            'url' => $canOpen ? PromoCodeResource::getUrl('view', ['record' => $row['code']]) : null,
            'uses' => $row['uses'],
            'discount' => Money::format($row['discount']),
        ], app(PromoCodeStats::class)->top(self::DAYS));
    }

    public function showLinks(): bool
    {
        return app(PermissionService::class)->allowsCurrent('promo_links.view');
    }

    /**
     * @return list<array{label: string, url: string, clicks: int, orders: int}>
     */
    public function links(): array
    {
        if (! $this->showLinks()) {
            return [];
        }

        return array_map(fn (array $row): array => [
            'label' => $row['link']->name,
            'url' => PromoLinkResource::getUrl('view', ['record' => $row['link']]),
            'clicks' => $row['clicks'],
            'orders' => $row['orders'],
        ], app(PromoLinkStats::class)->top(self::DAYS));
    }

    public function codesUrl(): string
    {
        return PromoCodeResource::getUrl('index');
    }

    public function linksUrl(): string
    {
        return PromoLinkResource::getUrl('index');
    }
}
