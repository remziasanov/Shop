<?php

declare(strict_types=1);

use App\Exceptions\FormGuardException;
use App\Services\Sales\FormGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

it('counts a submit before it decides, so the refused submit is in the counter (SEC-03)', function (): void {
    $guard = new FormGuard;
    $token = Crypt::encryptString((string) (now()->getTimestamp() - 10));

    foreach (range(1, FormGuard::MAX_ATTEMPTS) as $_) {
        $guard->check(null, $token, '203.0.113.5');
    }

    expect(fn () => $guard->check(null, $token, '203.0.113.5'))->toThrow(FormGuardException::class);
    expect(RateLimiter::attempts('sales-form:203.0.113.5'))->toBe(FormGuard::MAX_ATTEMPTS + 1);
});

it('keys the form limit by the IPv6 /64 network (SEC-03)', function (): void {
    $guard = new FormGuard;
    $token = Crypt::encryptString((string) (now()->getTimestamp() - 10));

    foreach (range(1, FormGuard::MAX_ATTEMPTS) as $i) {
        $guard->check(null, $token, '2001:db8:1:2::'.$i);
    }

    expect(fn () => $guard->check(null, $token, '2001:db8:1:2:ffff::9'))->toThrow(FormGuardException::class);
});
