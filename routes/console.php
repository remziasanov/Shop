<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('shop:heartbeat')->everyMinute()->withoutOverlapping(10);

// PR-02: promo start/end passes within 15 minutes; nightly full rebuild as a safety net (TZ section 8).
Schedule::command('shop:recalc-prices --boundaries')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('shop:recalc-prices --all')->dailyAt('03:10')->withoutOverlapping(10);

// SF-53: expire overdue reservations and return their stock (TZ section 8).
Schedule::command('shop:expire-reservations')->everyFiveMinutes()->withoutOverlapping(10);

// SF-82: the sitemap is rebuilt daily (and after an import by a queued job).
Schedule::command('shop:sitemap')->dailyAt('04:20')->withoutOverlapping(10);
