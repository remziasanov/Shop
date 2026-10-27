<?php

declare(strict_types=1);

use App\Console\Commands\CleanupCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduler table of TZ section 8: command => cron expression.
 */
dataset('schedule table', [
    'shop:heartbeat' => ['shop:heartbeat', '* * * * *'],
    'shop:expire-reservations' => ['shop:expire-reservations', '*/5 * * * *'],
    'shop:notify-expiring-reservations' => ['shop:notify-expiring-reservations', '0 * * * *'],
    'shop:recalc-prices --boundaries' => ['shop:recalc-prices --boundaries', '*/15 * * * *'],
    'shop:recalc-prices --all' => ['shop:recalc-prices --all', '10 3 * * *'],
    'shop:sitemap' => ['shop:sitemap', '20 4 * * *'],
    'shop:cleanup' => ['shop:cleanup', '40 4 * * *'],
    'queue:prune-failed' => ['queue:prune-failed --hours=168', '0 0 * * *'],
]);

it('registers every scheduled command exactly once with a 10 minute overlap mutex', function (string $command, string $expression): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_ends_with((string) $event->command, ' '.$command)
            || str_contains((string) $event->command, "'artisan' ".$command)
            || str_contains((string) $event->command, 'artisan '.$command));

    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
})->with('schedule table');

it('has no scheduled entries beyond the table', function (): void {
    expect(app(Schedule::class)->events())->toHaveCount(8);
});

it('keeps scheduled commands within the one-minute budget', function (): void {
    expect(CleanupCommand::BUDGET_SECONDS)->toBeLessThanOrEqual(50);
});
