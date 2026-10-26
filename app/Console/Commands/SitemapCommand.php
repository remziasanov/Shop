<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Seo\SitemapGenerator;
use Illuminate\Console\Command;

class SitemapCommand extends Command
{
    protected $signature = 'shop:sitemap';

    protected $description = 'Generate public/sitemap.xml (SF-82)';

    public function handle(SitemapGenerator $generator): int
    {
        $count = $generator->generate();

        $this->info("Sitemap written: {$count} URLs.");

        return self::SUCCESS;
    }
}
