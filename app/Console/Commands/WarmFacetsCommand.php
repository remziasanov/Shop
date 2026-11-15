<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmCategoryFacets;
use App\Services\Catalog\FacetWarmer;
use Illuminate\Console\Command;

class WarmFacetsCommand extends Command
{
    protected $signature = 'shop:warm-facets {--now : Warm in this process instead of queueing a job}';

    protected $description = 'Warm the facet cache of the category pages without filters (FL-11, FL-12)';

    public function handle(FacetWarmer $warmer): int
    {
        if ($this->option('now')) {
            $offset = 0;

            while ($offset !== null) {
                $offset = $warmer->warm($offset);
            }

            $this->info('Facets warmed.');

            return self::SUCCESS;
        }

        WarmCategoryFacets::dispatch();
        $this->info('Facet warming queued.');

        return self::SUCCESS;
    }
}
