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

    public int $tries = 5;

    /**
     * Seconds to wait before the next attempt after an exception (the generator failing must not retry at once).
     * A busy lock is released with its own 120 s delay in handle().
     *
     * @var list<int>
     */
    public array $backoff = [60, 120, 300, 600];

    public int $uniqueFor = 300;

    public function handle(SitemapGenerator $generator): void
    {
        // Another process (cron, a crawler request) is generating the file right now and may have read the data
        // before the import: try again later instead of dropping the run.
        if ($generator->generateLocked() === null) {
            $this->release(120);
        }
    }
}
