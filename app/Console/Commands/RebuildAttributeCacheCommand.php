<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Catalog\AttributeSyncService;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Console\Command;

/**
 * Rebuilds the JSON display cache `products.attributes` from the normalized tables (20.2). Until the migration from
 * JSON is finished, products that only have JSON are left untouched.
 */
class RebuildAttributeCacheCommand extends Command
{
    protected $signature = 'shop:rebuild-attribute-cache';

    protected $description = 'Rebuild the products.attributes JSON cache from the characteristic tables (FL-04, 20.2)';

    public function handle(AttributeSyncService $sync): int
    {
        $changed = $sync->rebuildAll();
        CatalogVersion::bump();

        $this->info(__('attributes.migration.command.rebuilt', ['count' => $changed]));

        return self::SUCCESS;
    }
}
