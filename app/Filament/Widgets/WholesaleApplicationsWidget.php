<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use App\Services\Customers\WholesaleApplicationService;
use App\Services\Users\PermissionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CG-21: wholesale applications waiting for a decision, with a link to the list.
 */
class WholesaleApplicationsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return app(PermissionService::class)->allowsCurrent('wholesale_applications.view');
    }

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        $count = app(WholesaleApplicationService::class)->pendingCount();

        return [
            Stat::make(__('wholesale.widget.new'), $count)
                ->description(__('wholesale.widget.new_hint'))
                ->color($count > 0 ? 'warning' : 'gray')
                ->url(WholesaleApplicationResource::getUrl('index')),
        ];
    }
}
