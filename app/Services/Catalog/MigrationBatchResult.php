<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Outcome of one batch of {@see JsonAttributeMigrator::migrateBatch()}.
 */
final class MigrationBatchResult
{
    /**
     * @param  int  $lastId  id of the last product read (continue after it)
     * @param  int  $products  products with JSON characteristics in the batch
     * @param  int  $migrated  products whose values were written
     * @param  int  $alreadyMigrated  products skipped because they already have rows in the tables
     * @param  int  $skippedValues  values that did not fit the type of their attribute (or had no attribute)
     * @param  bool  $done  there are no more products after this batch
     */
    public function __construct(
        public readonly int $lastId,
        public readonly int $products,
        public readonly int $migrated,
        public readonly int $alreadyMigrated,
        public readonly int $skippedValues,
        public readonly bool $done,
    ) {}
}
