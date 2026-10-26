<?php

declare(strict_types=1);

namespace App\Services\System;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Scheduler heartbeat (AD-11, D-005): a timestamp file, independent of the cache driver.
 */
class Heartbeat
{
    public function path(): string
    {
        return storage_path('app/heartbeat');
    }

    public function touch(): void
    {
        $path = $this->path();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, CarbonImmutable::now()->toIso8601String(), LOCK_EX);
    }

    public function lastRun(): ?CarbonImmutable
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        try {
            $raw = trim((string) file_get_contents($path));

            return $raw === '' ? null : CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    public function isStale(int $minutes = 5): bool
    {
        $last = $this->lastRun();

        return $last === null || $last->lt(CarbonImmutable::now()->subMinutes($minutes));
    }
}
