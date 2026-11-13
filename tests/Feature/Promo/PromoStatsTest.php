<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\PromoRedemption;
use App\Services\Promo\PromoCodeStats;
use App\Services\Promo\PromoLinkStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('counts clicks per day with an upsert and unique clicks separately (PM-25)', function (): void {
    $link = PromoLink::factory()->create();
    $stats = app(PromoLinkStats::class);
    $monday = Carbon::parse('2026-06-01 10:00:00');

    $stats->recordClick($link, true, $monday);
    $stats->recordClick($link, false, $monday->copy()->addHours(3));
    $stats->recordClick($link, true, $monday->copy()->addHours(5));
    $stats->recordClick($link, false, $monday->copy()->addDay());

    $rows = DB::table('promo_link_stats')->where('promo_link_id', $link->id)->orderBy('day')->get();

    expect($rows)->toHaveCount(2)
        ->and([(int) $rows[0]->clicks, (int) $rows[0]->unique_clicks])->toBe([3, 2])
        ->and([(int) $rows[1]->clicks, (int) $rows[1]->unique_clicks])->toBe([1, 0]);
});

it('counts orders and revenue of a link from the orders without cancelled and expired ones (PM-25)', function (): void {
    $link = PromoLink::factory()->create();
    $other = PromoLink::factory()->create();
    $stats = app(PromoLinkStats::class);
    $day = Carbon::parse('2026-06-01 10:00:00');

    $stats->recordClick($link, true, $day);
    $stats->recordClick($link, true, $day);
    $stats->recordClick($link, false, $day->copy()->addDay());
    $stats->recordClick($link, true, $day->copy()->addDay());

    Order::factory()->create(['promo_link_id' => $link->id, 'total' => '1500.50', 'created_at' => $day]);
    Order::factory()->create(['promo_link_id' => $link->id, 'total' => '500.00', 'created_at' => $day->copy()->addDay(), 'status' => OrderStatus::Completed]);
    Order::factory()->create(['promo_link_id' => $link->id, 'total' => '9999.00', 'status' => OrderStatus::Cancelled, 'created_at' => $day]);
    Order::factory()->reservation()->create(['promo_link_id' => $link->id, 'total' => '700.00', 'status' => OrderStatus::Expired, 'created_at' => $day]);
    Order::factory()->create(['promo_link_id' => $link->id, 'total' => '800.00', 'created_at' => $day])->delete();
    Order::factory()->create(['promo_link_id' => $other->id, 'total' => '123.00', 'created_at' => $day]);
    Order::factory()->create(['promo_link_id' => null, 'total' => '321.00', 'created_at' => $day]);

    $summary = $stats->summary($link);

    expect($summary)->toBe(['clicks' => 4, 'unique_clicks' => 3, 'orders' => 2, 'revenue' => 200_050, 'conversion' => 50.0])
        ->and($stats->perDay($link))->toBe([
            ['day' => '2026-06-01', 'clicks' => 2, 'unique_clicks' => 2, 'orders' => 1, 'revenue' => 150_050],
            ['day' => '2026-06-02', 'clicks' => 2, 'unique_clicks' => 1, 'orders' => 1, 'revenue' => 50_000],
        ])
        ->and($stats->summary($link, Carbon::parse('2026-06-02'), Carbon::parse('2026-06-02'))['orders'])->toBe(1)
        ->and($stats->summary($other)['conversion'])->toBeNull();
});

it('lists the links with the most clicks of the last days (22.4 widget)', function (): void {
    $a = PromoLink::factory()->create();
    $b = PromoLink::factory()->create();
    $stats = app(PromoLinkStats::class);

    $stats->recordClick($a, true);
    $stats->recordClick($b, true);
    $stats->recordClick($b, false);
    $stats->recordClick($a, true, now()->subDays(40));

    $top = $stats->top(30, 5);

    expect($top)->toHaveCount(2)->and($top[0]['link']->id)->toBe($b->id)->and($top[0]['clicks'])->toBe(2)->and($top[1]['clicks'])->toBe(1);
});

it('summarizes a promo code: uses, discounts, orders, revenue, average check (PM-17)', function (): void {
    $code = PromoCode::factory()->create();
    $day = Carbon::parse('2026-06-01 10:00:00');

    foreach ([['1000.00', '100.00', OrderStatus::New], ['2000.00', '300.00', OrderStatus::Completed]] as [$total, $discount, $status]) {
        $order = Order::factory()->create(['promo_code_id' => $code->id, 'total' => $total, 'promo_discount' => $discount, 'status' => $status, 'created_at' => $day]);
        PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'order_id' => $order->id, 'discount_amount' => $discount, 'created_at' => $day]);
    }

    $cancelled = Order::factory()->create(['promo_code_id' => $code->id, 'total' => '5000.00', 'status' => OrderStatus::Cancelled, 'created_at' => $day]);
    PromoRedemption::factory()->released()->create(['promo_code_id' => $code->id, 'order_id' => $cancelled->id, 'discount_amount' => '500.00', 'created_at' => $day]);

    $stats = app(PromoCodeStats::class);

    expect($stats->summary($code))->toBe(['uses' => 2, 'discount' => 40_000, 'orders' => 2, 'revenue' => 300_000, 'average_check' => 150_000])
        ->and($stats->perDay($code))->toBe([['day' => '2026-06-01', 'uses' => 2, 'discount' => 40_000, 'orders' => 2, 'revenue' => 300_000]])
        ->and($stats->summary($code, Carbon::parse('2026-07-01'))['orders'])->toBe(0)
        ->and($stats->summary($code, Carbon::parse('2026-07-01'))['average_check'])->toBe(0)
        ->and($stats->recentOrders($code)->pluck('id')->first())->toBe($cancelled->id)
        ->and($stats->recentOrders($code, 2))->toHaveCount(2);
});

it('limits the recent orders of a code to 20 (PM-17)', function (): void {
    $code = PromoCode::factory()->create();
    Order::factory()->count(25)->create(['promo_code_id' => $code->id]);

    expect(app(PromoCodeStats::class)->recentOrders($code))->toHaveCount(20);
});

it('lists all codes by uses and gives the top of the last 30 days (PM-17, 22.4)', function (): void {
    $few = PromoCode::factory()->create(['code' => 'FEWUSES']);
    $many = PromoCode::factory()->create(['code' => 'MANYUSES']);
    $none = PromoCode::factory()->create(['code' => 'NOUSES']);

    PromoRedemption::factory()->create(['promo_code_id' => $few->id, 'discount_amount' => '10.00']);

    foreach (range(1, 3) as $i) {
        PromoRedemption::factory()->create(['promo_code_id' => $many->id, 'discount_amount' => '50.00']);
    }

    PromoRedemption::factory()->create(['promo_code_id' => $none->id, 'created_at' => now()->subDays(60)]);

    $overview = app(PromoCodeStats::class)->overview();
    $top = app(PromoCodeStats::class)->top();

    expect($overview->pluck('code.code')->all())->toBe(['MANYUSES', 'FEWUSES', 'NOUSES'])
        ->and($overview->first())->toMatchArray(['uses' => 3, 'discount' => 15_000])
        ->and(app(PromoCodeStats::class)->overview('code')->pluck('code.code')->first())->toBe('FEWUSES')
        ->and(collect($top)->pluck('code.code')->all())->toBe(['MANYUSES', 'FEWUSES']);
});
