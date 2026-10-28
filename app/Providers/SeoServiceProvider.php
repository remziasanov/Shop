<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Jobs\GenerateSitemap;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Page;
use App\Models\Product;
use App\View\Composers\CookieBannerComposer;
use Closure;
use Illuminate\Support\Facades\DB;
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

        $this->rebuildSitemapOnVisibilityChanges();
    }

    /**
     * CG-15, TZ 25.8: the sitemap lists what the «Гость» group sees, so hiding something from guests must not wait
     * for the nightly run. The unique job is queued after commit; a burst of changes collapses into one run.
     * Creating a record only adds a URL, which the nightly run picks up, so creations are not listened to.
     */
    private function rebuildSitemapOnVisibilityChanges(): void
    {
        $request = static function (): void {
            if (config('shop.sitemap_rebuild_on_change') !== true) {
                return;
            }

            DB::afterCommit(static fn () => GenerateSitemap::dispatch());
        };

        $changed = static function (array $attributes) use ($request): Closure {
            return static function (Category|Page|Product $model) use ($attributes, $request): void {
                if ($model->wasChanged($attributes)) {
                    $request();
                }
            };
        };

        Event::listen('eloquent.saved: '.CustomerGroup::class, $request);
        Event::listen('eloquent.deleted: '.CustomerGroup::class, $request);

        Event::listen('eloquent.updated: '.Category::class, $changed(['is_active', 'parent_id', 'slug']));
        Event::listen('eloquent.updated: '.Page::class, $changed(['is_active', 'visible_group_ids', 'slug']));
        Event::listen('eloquent.updated: '.Product::class, $changed(['is_active', 'category_id', 'slug']));

        foreach ([Category::class, Page::class, Product::class] as $model) {
            Event::listen('eloquent.deleted: '.$model, $request);
        }
    }
}
