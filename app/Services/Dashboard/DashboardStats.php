<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\OrderType;
use App\Models\Import;
use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numbers of the admin dashboard (AD-10). Each block is a single aggregate query, cached for 60 seconds.
 */
final class DashboardStats
{
    public const TTL_SECONDS = 60;

    public const CHART_DAYS = 30;

    public const EXPIRING_HOURS = 24;

    private const KEYS = ['requests', 'expiring', 'products', 'chart'];

    /**
     * New requests created today and during the last 7 days (today included), split by type.
     *
     * @return array{today: array<string, int>, week: array<string, int>}
     */
    public function requests(): array
    {
        /** @var array{today: array<string, int>, week: array<string, int>} */
        return Cache::remember('dashboard:requests', self::TTL_SECONDS, function (): array {
            $todayStart = CarbonImmutable::now()->startOfDay();
            $weekStart = $todayStart->subDays(6);

            $rows = Order::query()
                ->where('created_at', '>=', $weekStart)
                ->selectRaw('type, count(*) as week_count, sum(case when created_at >= ? then 1 else 0 end) as today_count', [$todayStart])
                ->groupBy('type')
                ->get();

            $zero = array_fill_keys(array_map(fn (OrderType $type): string => $type->value, OrderType::cases()), 0);
            $result = ['today' => $zero, 'week' => $zero];

            foreach ($rows as $row) {
                $type = (string) $row->getRawOriginal('type');
                $result['week'][$type] = (int) $row->getAttribute('week_count');
                $result['today'][$type] = (int) $row->getAttribute('today_count');
            }

            return $result;
        });
    }

    /**
     * Active reservations that expire within the next 24 hours.
     */
    public function expiringReservations(): int
    {
        return (int) Cache::remember('dashboard:expiring', self::TTL_SECONDS,
            fn (): int => Order::query()->expiringWithin(self::EXPIRING_HOURS)->count());
    }

    /**
     * Catalog numbers: active products, active products without photos, active tracked products that are sold out.
     *
     * @return array{active: int, without_photo: int, out_of_stock: int}
     */
    public function products(): array
    {
        /** @var array{active: int, without_photo: int, out_of_stock: int} */
        return Cache::remember('dashboard:products', self::TTL_SECONDS, function (): array {
            $row = Product::query()
                ->where('is_active', true)
                ->selectRaw(
                    'count(*) as active_count, '
                    .'sum(case when not exists (select 1 from product_images where product_images.product_id = products.id) then 1 else 0 end) as no_photo_count, '
                    .'sum(case when stock_qty is not null and stock_qty - reserved_qty <= 0 then 1 else 0 end) as out_count'
                )
                ->first();

            return [
                'active' => (int) $row?->getAttribute('active_count'),
                'without_photo' => (int) $row?->getAttribute('no_photo_count'),
                'out_of_stock' => (int) $row?->getAttribute('out_count'),
            ];
        });
    }

    /**
     * Requests per day for the last 30 days (oldest first), missing days filled with zero.
     *
     * @return list<array{date: string, count: int}>
     */
    public function requestsByDay(): array
    {
        /** @var list<array{date: string, count: int}> */
        return Cache::remember('dashboard:chart', self::TTL_SECONDS, function (): array {
            $start = CarbonImmutable::now()->startOfDay()->subDays(self::CHART_DAYS - 1);

            $counts = Order::query()
                ->where('created_at', '>=', $start)
                ->select(DB::raw('DATE(created_at) as day'), DB::raw('count(*) as aggregate'))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->pluck('aggregate', 'day');

            $points = [];

            for ($i = 0; $i < self::CHART_DAYS; $i++) {
                $date = $start->addDays($i)->format('Y-m-d');
                $points[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
            }

            return $points;
        });
    }

    public function lastImport(): ?Import
    {
        return Import::query()
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first()
            ?? Import::query()->latest('id')->first();
    }

    public function forget(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget('dashboard:'.$key);
        }
    }
}
