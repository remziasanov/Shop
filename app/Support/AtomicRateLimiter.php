<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate-limit counters that stay exact under parallel requests on every cache store the shop supports.
 *
 * `RateLimiter::hit()` is a read followed by a write; on the file cache of the hosting (CACHE_STORE=file) parallel
 * requests read the same value and all pass a limit (security review of PR #51, SEC-03). Each change of a counter is
 * therefore done under a cache lock of that counter: the file store implements locks with `flock`, so they are exclusive
 * across PHP processes. If the lock cannot be taken in time, the request is treated as over every limit (fail closed).
 */
final class AtomicRateLimiter
{
    /** How long a counter lock may be held (a safety net if a process dies while holding it). */
    public const LOCK_SECONDS = 5;

    /** How long a request waits for the lock of a counter before it is refused. */
    public const WAIT_SECONDS = 3;

    /** Returned when the lock could not be taken: larger than any limit. */
    public const REFUSED = PHP_INT_MAX;

    /**
     * Counts one attempt and returns the new number of attempts in the window; compare it with the limit
     * («count first, then decide»).
     */
    public static function hit(string $key, int $decaySeconds, int $waitSeconds = self::WAIT_SECONDS): int
    {
        return self::locked($key, $waitSeconds, static fn (): int => RateLimiter::hit($key, $decaySeconds));
    }

    /**
     * Gives one attempt back (a refused request that must not use the quota).
     */
    public static function decrement(string $key, int $decaySeconds, int $waitSeconds = self::WAIT_SECONDS): void
    {
        self::locked($key, $waitSeconds, static fn (): int => RateLimiter::decrement($key, $decaySeconds));
    }

    /**
     * @param  Closure(): int  $callback
     */
    private static function locked(string $key, int $waitSeconds, Closure $callback): int
    {
        try {
            return Cache::lock('rate-limit:'.sha1($key), self::LOCK_SECONDS)->block($waitSeconds, $callback);
        } catch (LockTimeoutException) {
            return self::REFUSED;
        }
    }
}
