<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Progress of the background price recalculation (PR-05, D-027): total and done product counts under the
 * cache key `pricing.recalc`. Updates are serialized with a cache lock (works on the file store); when
 * done reaches total the key is forgotten, which is what the admin banner treats as "idle".
 */
final class RecalculationProgress
{
    public const CACHE_KEY = 'pricing.recalc';

    private const LOCK_KEY = 'pricing.recalc.lock';

    /** Safety net: a stuck counter (lost job) disappears on its own. */
    private const TTL_SECONDS = 3600;

    /**
     * Register $total more products to recalculate; starts a new run when none is active.
     */
    public function add(int $total): void
    {
        if ($total <= 0) {
            return;
        }

        $this->locked(function (CacheRepository $cache) use ($total): void {
            /** @var array{total: int, done: int, started_at: int}|null $state */
            $state = $cache->get(self::CACHE_KEY);
            $state ??= ['total' => 0, 'done' => 0, 'started_at' => now()->getTimestamp()];
            $state['total'] += $total;

            $cache->put(self::CACHE_KEY, $state, self::TTL_SECONDS);
        });
    }

    /**
     * Mark $count products as processed; clears the progress once everything is done.
     */
    public function advance(int $count): void
    {
        $this->locked(function (CacheRepository $cache) use ($count): void {
            /** @var array{total: int, done: int, started_at: int}|null $state */
            $state = $cache->get(self::CACHE_KEY);

            if ($state === null) {
                return;
            }

            $state['done'] += max(0, $count);

            if ($state['done'] >= $state['total']) {
                $cache->forget(self::CACHE_KEY);

                return;
            }

            $cache->put(self::CACHE_KEY, $state, self::TTL_SECONDS);
        });
    }

    /**
     * @return array{total: int, done: int, percent: int}|null null when no recalculation is running
     */
    public function current(): ?array
    {
        /** @var array{total: int, done: int, started_at: int}|null $state */
        $state = Cache::get(self::CACHE_KEY);

        if ($state === null || $state['total'] <= 0) {
            return null;
        }

        $done = min($state['done'], $state['total']);

        return [
            'total' => $state['total'],
            'done' => $done,
            'percent' => (int) floor($done * 100 / $state['total']),
        ];
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function locked(callable $callback): void
    {
        // The counter is only a hint for the banner: never fail a request or a job because of it.
        try {
            Cache::lock(self::LOCK_KEY, 10)->block(5, fn () => $callback(Cache::store()));
        } catch (LockTimeoutException) {
            Log::warning('Price recalculation progress was not updated: lock timeout.');
        }
    }
}
