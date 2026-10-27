<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OrderType;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\Dashboard\DashboardStats;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * AD-10: new requests today and over 7 days by type, reservations expiring within 24 hours.
 */
class RequestsStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $stats = app(DashboardStats::class);
        $requests = $stats->requests();
        $weekFrom = CarbonImmutable::now()->startOfDay()->subDays(6)->format('Y-m-d');
        $todayFrom = CarbonImmutable::now()->format('Y-m-d');

        $split = fn (array $counts): string => collect(OrderType::cases())
            ->map(fn (OrderType $type): string => $type->pluralLabel().': '.$counts[$type->value])
            ->implode(' · ');

        $result = [
            Stat::make(__('dashboard.requests.today'), array_sum($requests['today']))
                ->description($split($requests['today']))
                ->url($this->ordersUrl(null, $todayFrom)),
        ];

        foreach (OrderType::cases() as $type) {
            $result[] = Stat::make(__('dashboard.requests.week', ['type' => $type->pluralLabel()]), $requests['week'][$type->value])
                ->description(__('dashboard.requests.today_of', ['count' => $requests['today'][$type->value]]))
                ->url($this->ordersUrl($type, $weekFrom));
        }

        $expiring = $stats->expiringReservations();
        $result[] = Stat::make(__('dashboard.requests.expiring'), $expiring)
            ->description(__('dashboard.requests.expiring_hint'))
            ->color($expiring > 0 ? 'warning' : 'gray')
            ->url($this->ordersUrl(OrderType::Reservation, null));

        return $result;
    }

    private function ordersUrl(?OrderType $type, ?string $from): string
    {
        $query = ['activeTab' => $type === null ? 'all' : $type->value];

        if ($from !== null) {
            $query['tableFilters'] = ['period' => ['created_from' => $from]];
        }

        return OrderResource::getUrl('index', $query);
    }
}
