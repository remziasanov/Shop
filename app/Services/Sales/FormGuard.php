<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Exceptions\FormGuardException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spam protection of the checkout, reservation and inquiry forms (SF-48, D-048): a honeypot field, a signed
 * render timestamp (a submit earlier than 3 s after rendering is rejected) and 5 submits per minute per IP
 * (one shared limit for all three forms); in the Telegram Mini App also 5 per minute per Telegram user (TM-05).
 */
final class FormGuard
{
    public const MIN_SECONDS = 3;

    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /**
     * Value for a hidden field, issued when the form is rendered.
     */
    public function issueToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Honeypot and minimum fill time only (no rate limit): for forms with limits of their own, like the registration.
     *
     * @throws FormGuardException
     */
    public function checkSpam(?string $honeypot, ?string $token): void
    {
        if ($honeypot !== null && $honeypot !== '') {
            throw new FormGuardException(__('sales.guard.generic'));
        }

        if ($token === null || $token === '') {
            throw new FormGuardException(__('sales.guard.generic'));
        }

        try {
            $issuedAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            throw new FormGuardException(__('sales.guard.generic'));
        }

        if (now()->getTimestamp() - $issuedAt < self::MIN_SECONDS) {
            throw new FormGuardException(__('sales.guard.too_fast'));
        }
    }

    /**
     * @throws FormGuardException
     */
    public function check(?string $honeypot, ?string $token, string $ip, ?int $telegramUserId = null): void
    {
        $this->checkSpam($honeypot, $token);

        // TM-05: a Mini App visitor is limited by the Telegram user as well (several people may share one IP).
        $keys = ['sales-form:'.$ip];

        if ($telegramUserId !== null) {
            $keys[] = 'sales-form:tg:'.$telegramUserId;
        }

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw new FormGuardException(__('sales.guard.rate_limited'));
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }
}
