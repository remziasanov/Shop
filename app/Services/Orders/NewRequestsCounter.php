<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * Counts of requests in the `new` status: the badge on the admin menu item (AD-04, cached for 30 s)
 * and the per-tab badges of the list (OR-01, always fresh).
 */
final class NewRequestsCounter
{
    public const CACHE_KEY = 'orders:new-count';

    public const TTL_SECONDS = 30;

    public function total(): int
    {
        return (int) Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): int => Order::query()->new()->count());
    }

    /**
     * @return array<string, int> order type value => count
     */
    public function byType(): array
    {
        $counts = array_fill_keys(array_map(fn (OrderType $type): string => $type->value, OrderType::cases()), 0);

        $rows = Order::query()
            ->where('status', OrderStatus::New->value)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        foreach ($rows as $type => $count) {
            $counts[(string) $type] = (int) $count;
        }

        return $counts;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
