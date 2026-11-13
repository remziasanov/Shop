<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\OrderStatus;
use App\Models\PromoLink;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Click counters and statistics of promo links (PM-25). Clicks are one row per link and day, written with an upsert;
 * orders and revenue are read from `orders.promo_link_id` (cancelled and expired requests do not count), so they cannot
 * drift from the orders themselves. Money is integer kopecks.
 *
 * @phpstan-type Summary array{clicks: int, unique_clicks: int, orders: int, revenue: int, conversion: float|null}
 */
final class PromoLinkStats
{
    /**
     * One more click today; `$isUnique` is true for the first visit with this cookie. Safe under parallel clicks.
     */
    public function recordClick(PromoLink $link, bool $isUnique, ?CarbonInterface $now = null): void
    {
        DB::table('promo_link_stats')->upsert(
            [['promo_link_id' => $link->id, 'day' => ($now ?? now())->toDateString(), 'clicks' => 1, 'unique_clicks' => $isUnique ? 1 : 0]],
            ['promo_link_id', 'day'],
            ['clicks' => DB::raw('clicks + 1'), 'unique_clicks' => DB::raw('unique_clicks + '.($isUnique ? 1 : 0))],
        );
    }

    /**
     * Clicks, unique clicks, orders, revenue and conversion (orders per click, percent, null without clicks) of a link.
     *
     * @return Summary
     */
    public function summary(PromoLink $link, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $clicks = DB::table('promo_link_stats')->where('promo_link_id', $link->id);

        if ($from !== null) {
            $clicks->where('day', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $clicks->where('day', '<=', $to->toDateString());
        }

        $row = $clicks->selectRaw('COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(unique_clicks), 0) as unique_clicks')->first();
        $totalClicks = (int) ($row->clicks ?? 0);
        $orders = $this->orders($link, $from, $to)->selectRaw('COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')->first();
        $count = (int) ($orders->n ?? 0);

        return [
            'clicks' => $totalClicks,
            'unique_clicks' => (int) ($row->unique_clicks ?? 0),
            'orders' => $count,
            'revenue' => Money::fromRubles((string) ($orders->revenue ?? 0)),
            'conversion' => $totalClicks === 0 ? null : round($count * 100 / $totalClicks, 2),
        ];
    }

    /**
     * The chart: one entry per day that has clicks or orders, oldest first.
     *
     * @return list<array{day: string, clicks: int, unique_clicks: int, orders: int, revenue: int}>
     */
    public function perDay(PromoLink $link, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $days = [];

        $clicks = DB::table('promo_link_stats')->where('promo_link_id', $link->id);

        if ($from !== null) {
            $clicks->where('day', '>=', $from->toDateString());
        }

        if ($to !== null) {
            $clicks->where('day', '<=', $to->toDateString());
        }

        foreach ($clicks->get(['day', 'clicks', 'unique_clicks']) as $row) {
            $day = substr((string) $row->day, 0, 10);
            $days[$day] = ['day' => $day, 'clicks' => (int) $row->clicks, 'unique_clicks' => (int) $row->unique_clicks, 'orders' => 0, 'revenue' => 0];
        }

        $orders = $this->orders($link, $from, $to)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n, COALESCE(SUM(total), 0) as revenue')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        foreach ($orders as $row) {
            $day = substr((string) $row->day, 0, 10);
            $days[$day] ??= ['day' => $day, 'clicks' => 0, 'unique_clicks' => 0, 'orders' => 0, 'revenue' => 0];
            $days[$day]['orders'] = (int) $row->n;
            $days[$day]['revenue'] = Money::fromRubles((string) $row->revenue);
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * The dashboard widget (22.4): links with the most clicks in the last N days.
     *
     * @return list<array{link: PromoLink, clicks: int, orders: int}>
     */
    public function top(int $days = 30, int $limit = 5): array
    {
        $rows = DB::table('promo_link_stats')
            ->where('day', '>=', now()->subDays($days)->toDateString())
            ->groupBy('promo_link_id')
            ->orderByDesc(DB::raw('SUM(clicks)'))
            ->limit($limit)
            ->selectRaw('promo_link_id, SUM(clicks) as clicks')
            ->get();

        $links = PromoLink::query()->whereIn('id', $rows->pluck('promo_link_id')->all())->get()->keyBy('id');
        $top = [];

        foreach ($rows as $row) {
            $link = $links->get($row->promo_link_id);

            if ($link !== null) {
                $top[] = [
                    'link' => $link,
                    'clicks' => (int) $row->clicks,
                    'orders' => (int) $this->orders($link, now()->subDays($days))->count(),
                ];
            }
        }

        return $top;
    }

    /**
     * Requests attributed to the link without cancelled and expired ones (PM-25).
     *
     * @return Builder
     */
    private function orders(PromoLink $link, ?CarbonInterface $from = null, ?CarbonInterface $to = null)
    {
        $query = DB::table('orders')
            ->where('promo_link_id', $link->id)
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
