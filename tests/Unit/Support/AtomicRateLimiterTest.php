<?php

declare(strict_types=1);

use App\Support\AtomicRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('atomic-test');
});

it('counts first and returns the new number of attempts (SEC-03)', function (): void {
    expect(AtomicRateLimiter::hit('atomic-test', 60))->toBe(1)
        ->and(AtomicRateLimiter::hit('atomic-test', 60))->toBe(2)
        ->and(RateLimiter::attempts('atomic-test'))->toBe(2);

    AtomicRateLimiter::decrement('atomic-test', 60);

    expect(RateLimiter::attempts('atomic-test'))->toBe(1);
});

it('refuses (fails closed) and counts nothing while another process holds the lock of the counter (SEC-03)', function (): void {
    $lock = Cache::lock('rate-limit:'.sha1('atomic-test'), AtomicRateLimiter::LOCK_SECONDS);
    expect($lock->get())->toBeTrue();

    try {
        expect(AtomicRateLimiter::hit('atomic-test', 60, 0))->toBe(AtomicRateLimiter::REFUSED)
            ->and(RateLimiter::attempts('atomic-test'))->toBe(0);
    } finally {
        $lock->release();
    }

    expect(AtomicRateLimiter::hit('atomic-test', 60, 0))->toBe(1);
});
