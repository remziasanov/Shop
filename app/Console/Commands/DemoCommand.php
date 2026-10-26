<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\DemoCatalogSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('shop:demo {--force : Allow running in production} {--fresh : Delete existing demo data first} {--no-images : Do not generate photos} {--limit= : Only the first N products}')]
#[Description('Create demo catalog data: categories, products and generated photos')]
class DemoCommand extends Command
{
    public function handle(DemoCatalogSeeder $seeder): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error(__('demo.production_refused'));

            return self::FAILURE;
        }

        $limit = $this->option('limit');

        if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->components->error(__('demo.invalid_limit'));

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $deleted = $seeder->fresh();
            $this->components->info(__('demo.fresh_done', ['products' => $deleted['products'], 'categories' => $deleted['categories']]));
        }

        $withImages = ! $this->option('no-images');
        $report = $seeder->run($withImages, $limit !== null ? (int) $limit : null);

        $this->components->info(__('demo.summary_title'));
        $this->line(__('demo.categories', ['created' => $report['categories_created'], 'updated' => $report['categories_updated']]));
        $this->line(__('demo.products', ['created' => $report['products_created'], 'updated' => $report['products_updated']]));
        $this->line($withImages
            ? __('demo.images', ['created' => $report['images_created'], 'skipped' => $report['images_skipped']])
            : __('demo.images_disabled'));

        foreach ($report['warnings'] as $warning) {
            $this->components->warn(__('demo.warning', ['message' => $warning]));
        }

        return self::SUCCESS;
    }
}
