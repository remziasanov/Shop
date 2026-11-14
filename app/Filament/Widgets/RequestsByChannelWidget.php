<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OrderChannel;
use App\Services\Dashboard\DashboardStats;
use App\Services\Users\PermissionService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * TM-14: «Обращения по каналам за 30 дней»: the number and the sum of the requests that came from the site, the
 * installed app and the Telegram Mini App.
 */
class RequestsByChannelWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return app(PermissionService::class)->allowsCurrent('orders.view');
    }

    protected function getHeading(): ?string
    {
        return __('dashboard.channels.heading');
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $channels = app(DashboardStats::class)->requestsByChannel();
        $stats = [];

        foreach (OrderChannel::cases() as $channel) {
            $row = $channels[$channel->value];
            $stats[] = Stat::make($channel->label(), $row['count'])
                ->description(__('dashboard.channels.sum', ['sum' => Money::format($row['sum'])]));
        }

        return $stats;
    }
}
