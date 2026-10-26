<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('shop:heartbeat')->everyMinute()->withoutOverlapping();

// PR-02: promo start/end passes within 15 minutes; nightly full rebuild as a safety net (TZ section 8).
Schedule::command('shop:recalc-prices --boundaries')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('shop:recalc-prices --all')->dailyAt('03:10')->withoutOverlapping();
