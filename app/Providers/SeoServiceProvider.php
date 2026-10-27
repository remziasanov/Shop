<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Jobs\GenerateSitemap;
use App\View\Composers\CookieBannerComposer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * SEO, counters, sitemap and the cookie banner (stage 5, SF-08, SF-81…SF-84).
 */
class SeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('storefront.partials.cookie-banner', CookieBannerComposer::class);

        // SF-82: the catalog changed, rebuild the sitemap in the queue (a failed or cancelled import changed nothing).
        Event::listen(ImportFinished::class, function (ImportFinished $event): void {
            if (in_array($event->import->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true)) {
                GenerateSitemap::dispatch();
            }
        });
    }
}
