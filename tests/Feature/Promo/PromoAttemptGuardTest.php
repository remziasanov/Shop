<?php

declare(strict_types=1);

use App\Services\Promo\PromoAttemptGuard;

beforeEach(function (): void {
    $this->guard = app(PromoAttemptGuard::class);
});

it('allows ten attempts a minute and then asks to wait (PM-14)', function (): void {
    for ($i = 0; $i < 10; $i++) {
        expect($this->guard->retryAfter('203.0.113.5', 's1'))->toBe(0);
        $this->guard->hit('203.0.113.5', 's1');
    }

    $wait = $this->guard->retryAfter('203.0.113.5', 's1');

    expect($wait)->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($this->guard->message($wait))->toStartWith('Слишком много попыток');

    $this->travel(61)->seconds();

    expect($this->guard->retryAfter('203.0.113.5', 's1'))->toBe(0);
});

it('limits by IP across sessions and by session across IPs (PM-14)', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $this->guard->hit('203.0.113.5', 'session-'.$i);
    }

    // The same IP with a brand new session is blocked; another IP with a fresh session is not.
    expect($this->guard->retryAfter('203.0.113.5', 'fresh'))->toBeGreaterThan(0)
        ->and($this->guard->retryAfter('203.0.113.6', 'fresh'))->toBe(0);

    for ($i = 0; $i < 10; $i++) {
        $this->guard->hit('198.51.100.'.$i, 'shared-session');
    }

    expect($this->guard->retryAfter('198.51.100.99', 'shared-session'))->toBeGreaterThan(0)
        ->and($this->guard->retryAfter('198.51.100.99', 'other-session'))->toBe(0);
});

it('allows 50 attempts an hour (PM-14)', function (): void {
    // Five rounds of ten attempts, a pause of a minute between them: the minute limit never trips, the hour limit does.
    for ($round = 0; $round < 5; $round++) {
        for ($i = 0; $i < 10; $i++) {
            $this->guard->hit('203.0.113.7', 'hourly');
        }

        $this->travel(61)->seconds();
    }

    $wait = $this->guard->retryAfter('203.0.113.7', 'hourly');

    expect($wait)->toBeGreaterThan(60)->toBeLessThanOrEqual(3600);

    $this->travel(3600)->seconds();

    expect($this->guard->retryAfter('203.0.113.7', 'hourly'))->toBe(0);
});

it('pauses for 30 seconds after five failed attempts in a row (PM-14)', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->guard->failed('203.0.113.8', 's8');
    }

    expect($this->guard->retryAfter('203.0.113.8', 's8'))->toBe(0);

    $this->guard->failed('203.0.113.8', 's8');

    expect($this->guard->retryAfter('203.0.113.8', 's8'))->toBeGreaterThan(25)->toBeLessThanOrEqual(30);

    $this->travel(31)->seconds();

    expect($this->guard->retryAfter('203.0.113.8', 's8'))->toBe(0);
});

it('restarts the series of failures after a success (PM-14)', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->guard->failed('203.0.113.9', 's9');
    }

    $this->guard->succeeded('203.0.113.9', 's9');
    $this->guard->failed('203.0.113.9', 's9');

    expect($this->guard->retryAfter('203.0.113.9', 's9'))->toBe(0);
});
