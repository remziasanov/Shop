<?php

declare(strict_types=1);

use App\Exceptions\FormGuardException;
use App\Services\Sales\FormGuard;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('sales-form:10.0.0.1');
    $this->guard = new FormGuard;
});

it('accepts a human submission after the minimum time', function () {
    $token = $this->guard->issueToken();
    $this->travel(4)->seconds();

    $this->guard->check('', $token, '10.0.0.1');

    expect(true)->toBeTrue();
});

it('rejects a filled honeypot', function () {
    $token = $this->guard->issueToken();
    $this->travel(10)->seconds();

    expect(fn () => $this->guard->check('http://spam', $token, '10.0.0.1'))->toThrow(FormGuardException::class);
});

it('rejects a submit faster than 3 seconds', function () {
    $token = $this->guard->issueToken();
    $this->travel(1)->seconds();

    expect(fn () => $this->guard->check(null, $token, '10.0.0.1'))->toThrow(FormGuardException::class, 'Слишком быстро');
});

it('rejects a missing or forged token', function () {
    expect(fn () => $this->guard->check(null, null, '10.0.0.1'))->toThrow(FormGuardException::class)
        ->and(fn () => $this->guard->check(null, 'garbage', '10.0.0.1'))->toThrow(FormGuardException::class);
});

it('allows 5 submits per minute per IP and blocks the sixth', function () {
    $token = $this->guard->issueToken();
    $this->travel(5)->seconds();

    for ($i = 0; $i < 5; $i++) {
        $this->guard->check(null, $token, '10.0.0.1');
    }

    expect(fn () => $this->guard->check(null, $token, '10.0.0.1'))->toThrow(FormGuardException::class, 'Слишком много отправок');

    // Another IP is not affected.
    $this->guard->check(null, $token, '10.0.0.2');
    RateLimiter::clear('sales-form:10.0.0.2');
});
