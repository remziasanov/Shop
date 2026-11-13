<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PromoCode;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistics of promo codes (PM-17). Money is integer kopecks. «Применений» and «сумма скидок» come from the
 * redemptions that were not given back; orders and revenue from the orders of the code that are not cancelled or expired.
 *
 * @phpstan-type Summary array{uses: int, discount: int, orders: int, revenue: int, average_check: int}
 */
final class PromoCodeStats
{
    /**
     * @return Summary
     */
    public function summary(PromoCode $code, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $uses = $this->redemptions($code, $from, $to)->selectRaw('COUNT(*) as n, COALESCE(SUM(discount_amount), 0) as discount')->first();
        $orders = $this->orders($code, $from, $to)->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')->first();
        $count = (int) ($orders->n ?? 0);
        $revenue = Money::fromRubles((string) ($orders->revenue ?? 0));

        return [
            'uses' => (int) ($uses->n ?? 0),
            'discount' => Money::fromRubles((string) ($uses->discount ?? 0)),
            'orders' => $count,
            'revenue' => $revenue,
            'average_check' => $count === 0 ? 0 : intdiv($revenue + intdiv($count, 2), $count),
        ];
    }

    /**
     * The chart: orders, revenue and discount per day, oldest first.
     *
     * @return list<array{day: string, uses: int, discount: int, orders: int, revenue: int}>
     */
    public function perDay(PromoCode $code, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $days = [];

        $uses = $this->redemptions($code, $from, $to)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n, COALESCE(SUM(discount_amount), 0) as discount')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        foreach ($uses as $row) {
            $day = substr((string) $row->day, 0, 10);
            $days[$day] = ['day' => $day, 'uses' => (int) $row->n, 'discount' => Money::fromRubles((string) $row->discount), 'orders' => 0, 'revenue' => 0];
        }

        $orders = $this->orders($code, $from, $to)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        foreach ($orders as $row) {
            $day = substr((string) $row->day, 0, 10);
            $days[$day] ??= ['day' => $day, 'uses' => 0, 'discount' => 0, 'orders' => 0, 'revenue' => 0];
            $days[$day]['orders'] = (int) $row->n;
            $days[$day]['revenue'] = Money::fromRubles((string) $row->revenue);
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * The latest orders with the code, newest first (the tab shows 20).
     *
     * @return Collection<int, Order>
     */
    public function recentOrders(PromoCode $code, int $limit = 20): Collection
    {
        return Order::query()->where('promo_code_id', $code->id)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * The table of all codes with their uses (not given back) and discount total, most used first by default.
     *
     * @param  'uses'|'discount'|'code'  $sort
     * @return Collection<int, array{code: PromoCode, uses: int, discount: int}>
     */
    public function overview(string $sort = 'uses', int $limit = 100): Collection
    {
        $stats = DB::table('promo_redemptions')
            ->whereNull('released_at')
            ->groupBy('promo_code_id')
            ->selectRaw('promo_code_id, COUNT(*) as uses, COALESCE(SUM(discount_amount), 0) as discount')
            ->get()
            ->keyBy('promo_code_id');

        $rows = PromoCode::query()->get()->map(function (PromoCode $code) use ($stats): array {
            $row = $stats->get($code->id);

            return ['code' => $code, 'uses' => (int) ($row->uses ?? 0), 'discount' => Money::fromRubles((string) ($row->discount ?? 0))];
        });

        $rows = match ($sort) {
            'discount' => $rows->sortByDesc('discount'),
            'code' => $rows->sortBy(fn (array $row): string => $row['code']->code),
            default => $rows->sortByDesc('uses'),
        };

        return $rows->take($limit)->values();
    }

    /**
     * The dashboard widget (22.4): codes with the most uses in the last N days.
     *
     * @return list<array{code: PromoCode, uses: int, discount: int}>
     */
    public function top(int $days = 30, int $limit = 5): array
    {
        $rows = DB::table('promo_redemptions')
            ->whereNull('released_at')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('promo_code_id')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->selectRaw('promo_code_id, COUNT(*) as uses, COALESCE(SUM(discount_amount), 0) as discount')
            ->get();

        $codes = PromoCode::query()->whereIn('id', $rows->pluck('promo_code_id')->all())->get()->keyBy('id');
        $top = [];

        foreach ($rows as $row) {
            $code = $codes->get($row->promo_code_id);

            if ($code !== null) {
                $top[] = ['code' => $code, 'uses' => (int) $row->uses, 'discount' => Money::fromRubles((string) $row->discount)];
            }
        }

        return $top;
    }

    /**
     * @return Builder
     */
    private function redemptions(PromoCode $code, ?CarbonInterface $from, ?CarbonInterface $to)
    {
        $query = DB::table('promo_redemptions')->where('promo_code_id', $code->id)->whereNull('released_at');

        if ($from !== null) {
            $query->where('created_at', '>=', $from->copy()->startOfDay());
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * @return Builder
     */
    private function orders(PromoCode $code, ?CarbonInterface $from, ?CarbonInterface $to)
    {
        $query = DB::table('orders')
            ->where('promo_code_id', $code->id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Expired->value]);

        if ($from !== null) {
            $query->where('created_at', '>=', $from->copy()->startOfDay());
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }
}
