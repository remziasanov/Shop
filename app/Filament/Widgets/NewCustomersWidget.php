<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Users\PermissionService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * CA-23: new registrations of the last 7 days (the shop is not notified of each one, D-088), and how many of them
 * have not confirmed the address yet.
 */
class NewCustomersWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return app(PermissionService::class)->allowsCurrent('customers.view');
    }

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        $weekStart = CarbonImmutable::now()->startOfDay()->subDays(6);
        $week = Customer::query()->where('created_at', '>=', $weekStart)->count();
        $today = Customer::query()->where('created_at', '>=', CarbonImmutable::now()->startOfDay())->count();
        $unverified = Customer::query()->where('created_at', '>=', $weekStart)->whereNull('email_verified_at')->count();

        $period = ['registered_from' => $weekStart->format('Y-m-d')];

        return [
            Stat::make(__('customers.widget.new_week'), $week)
                ->description(__('customers.widget.new_week_hint', ['today' => $today]))
                ->url(CustomerResource::getUrl('index', ['tableFilters' => ['registered' => $period]])),
            Stat::make(__('customers.widget.unverified'), $unverified)
                ->description(__('customers.widget.unverified_hint'))
                ->color($unverified > 0 ? 'warning' : 'gray')
                ->url(CustomerResource::getUrl('index', ['tableFilters' => ['registered' => $period, 'verified' => ['value' => 0]]])),
        ];
    }
}
