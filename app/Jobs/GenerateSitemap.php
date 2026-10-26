<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Seo\SitemapGenerator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds the sitemap after an import (SF-82). Streams and chunks the queries, so it stays well within the job time limit.
 */
class GenerateSitemap implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(SitemapGenerator $generator): void
    {
        // Skipped while another process (cron, a crawler request) is generating the same file.
        $generator->generateLocked();
    }
}
