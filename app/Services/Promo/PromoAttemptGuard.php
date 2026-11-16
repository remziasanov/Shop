<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Support\AtomicRateLimiter;
use App\Support\ClientIpKey;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

/**
 * Brute-force protection of the promo code input (PM-14): at most 10 attempts a minute and 50 an hour per IP address
 * and per session, and a 30 second pause after 5 failed attempts in a row. Everything lives in the cache store
 * (`file` on the hosting), so no table is needed; the failure counter and the pause are kept per IP and per session too.
 *
 * The IP subject is {@see ClientIpKey} (an IPv6 host by its /64 network) and attempts are counted first and compared
 * after, atomically (RE-02, D-209). Use: {@see attempt()} before evaluating a code (a positive number means «too many
 * attempts», do not evaluate), then {@see failed()} or {@see succeeded()} with the outcome.
 */
final class PromoAttemptGuard
{
    public const PER_MINUTE = 10;

    public const PER_HOUR = 50;

    public const FAILURES_BEFORE_PAUSE = 5;

    public const PAUSE_SECONDS = 30;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $cache,
    ) {}

    /**
     * Counts the attempt first and then decides: seconds the visitor has to wait (0 = the attempt is allowed, it is
     * counted). A refused attempt keeps its hit.
     */
    public function attempt(string $ip, string $sessionId): int
    {
        $wait = 0;

        foreach ($this->subjects($ip, $sessionId) as $subject) {
            $until = $this->cache->get($this->key('pause', $subject));

            if (is_int($until)) {
                $wait = max($wait, $until - Carbon::now()->getTimestamp());
            }

            foreach ([['minute', self::PER_MINUTE, 60], ['hour', self::PER_HOUR, 3600]] as [$kind, $max, $decay]) {
                $key = $this->key($kind, $subject);

                if (AtomicRateLimiter::hit($key, $decay) > $max) {
                    $wait = max($wait, $this->limiter->availableIn($key), 1);
                }
            }
        }

        return max(0, $wait);
    }

    /**
     * A refused code: the fifth failure in a row starts the pause.
     */
    public function failed(string $ip, string $sessionId): void
    {
        foreach ($this->subjects($ip, $sessionId) as $subject) {
            $key = $this->key('fails', $subject);
            $this->cache->add($key, 0, 3600);
            $count = (int) $this->cache->increment($key);

            if ($count >= self::FAILURES_BEFORE_PAUSE) {
                $this->cache->put($this->key('pause', $subject), Carbon::now()->getTimestamp() + self::PAUSE_SECONDS, self::PAUSE_SECONDS);
            }
        }
    }

    /**
     * An accepted code ends the series of failures.
     */
    public function succeeded(string $ip, string $sessionId): void
    {
        foreach ($this->subjects($ip, $sessionId) as $subject) {
            $this->cache->forget($this->key('fails', $subject));
        }
    }

    /**
     * «Слишком много попыток. Повторите через N с.»
     */
    public function message(int $seconds): string
    {
        return __('promo.messages.throttled', ['seconds' => max(1, $seconds)]);
    }

    /**
     * @return list<string>
     */
    private function subjects(string $ip, string $sessionId): array
    {
        return ['ip:'.sha1(ClientIpKey::of($ip)), 'session:'.sha1($sessionId)];
    }

    private function key(string $kind, string $subject): string
    {
        return 'promo-attempt:'.$kind.':'.$subject;
    }
}
