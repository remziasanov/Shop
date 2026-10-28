<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Dashboard\DashboardStats;
use App\Services\Users\PermissionService;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * AD-10: requests per day for the last 30 days.
 */
class RequestsChartWidget extends ChartWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return app(PermissionService::class)->allowsCurrent('orders.view');
    }

    protected ?string $maxHeight = '260px';

    public function getHeading(): ?string
    {
        return __('dashboard.chart.heading');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $points = app(DashboardStats::class)->requestsByDay();

        return [
            'datasets' => [
                [
                    'label' => __('dashboard.chart.label'),
                    'data' => array_column($points, 'count'),
                    'fill' => 'start',
                    'tension' => 0.2,
                ],
            ],
            'labels' => array_map(fn (array $point): string => CarbonImmutable::parse($point['date'])->format('d.m'), $points),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
