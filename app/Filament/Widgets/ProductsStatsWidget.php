<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Services\Dashboard\DashboardStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * AD-10: catalog health numbers.
 */
class ProductsStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $products = app(DashboardStats::class)->products();
        $url = ProductResource::getUrl('index');

        return [
            Stat::make(__('dashboard.products.active'), $products['active'])->url($url),
            Stat::make(__('dashboard.products.without_photo'), $products['without_photo'])
                ->color($products['without_photo'] > 0 ? 'warning' : 'success')
                ->url($url),
            Stat::make(__('dashboard.products.out_of_stock'), $products['out_of_stock'])
                ->color($products['out_of_stock'] > 0 ? 'warning' : 'success')
                ->url($url),
        ];
    }
}
