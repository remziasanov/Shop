<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Queues a group of notification jobs atomically. The `database` queue lives in the same connection, so the
 * inserts are part of the surrounding transaction (hence `beforeCommit`: `afterCommit` would push them only after it
 * and a failure could no longer roll the group back). If anything fails, nothing stays queued, the locks of the unique
 * jobs are released and the caller can safely be retried without duplicates or lost messages.
 */
final class JobFanOut
{
    /**
     * @param  list<ShouldQueue>  $jobs
     * @return int number of jobs queued (a job whose unique lock is already held is skipped: it is queued or just sent)
     *
     * @throws Throwable
     */
    public static function dispatch(array $jobs): int
    {
        $locks = new UniqueLock(app(Cache::class));
        $locked = [];
        $queued = 0;

        try {
            DB::transaction(function () use ($jobs, $locks, &$locked, &$queued): void {
                foreach ($jobs as $job) {
                    if ($job instanceof ShouldBeUnique) {
                        if (! $locks->acquire($job)) {
                            continue;
                        }

                        $locked[] = $job;
                    }

                    if (method_exists($job, 'beforeCommit')) {
                        $job->beforeCommit();
                    }

                    app(Dispatcher::class)->dispatch($job);
                    $queued++;
                }
            });
        } catch (Throwable $e) {
            foreach ($locked as $job) {
                $locks->release($job);
            }

            throw $e;
        }

        return $queued;
    }
}
