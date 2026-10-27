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

// Stage 6: Telegram warning about reservations expiring within 6 hours, once per reservation (TZ section 8).
Schedule::command('shop:notify-expiring-reservations')->hourly()->withoutOverlapping(10);

// Nightly housekeeping: old carts, import/export files, abandoned uploads (TZ section 8).
Schedule::command('shop:cleanup')->dailyAt('04:40')->withoutOverlapping(10);

// Failed queue jobs are kept for a week (TZ section 8).
// schedule:run executes due tasks one after another within a minute, so the heavy daily tasks are staggered
// (03:10, 04:20, 04:40, 04:50), each within ~50 s (D-059).
Schedule::command('queue:prune-failed --hours=168')->dailyAt('04:50')->withoutOverlapping(10);
