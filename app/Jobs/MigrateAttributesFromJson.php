<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\JsonAttributeMigrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Background migration of characteristics from JSON (FL-04, D-076): batches of 500 products with the progress
 * kept in {@see AttributeMigrationState}. A run stops after about {@see self::BUDGET_SECONDS}
 * seconds (a batch takes a few seconds, the job limit is 40 s) and queues itself again until everything is done.
 * Resumable and idempotent: a failed or repeated run continues from the stored position.
 */
class MigrateAttributesFromJson implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BUDGET_SECONDS = 25;

    public int $tries = 3;

    public int $timeout = 40;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('attributes-migration'))->releaseAfter(30)->expireAfter(60)];
    }

    public function handle(JsonAttributeMigrator $migrator): void
    {
        if (! $migrator->run(self::BUDGET_SECONDS)) {
            self::dispatch();
        }
    }
}
