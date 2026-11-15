<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\ImportStatus;
use App\Jobs\GenerateSitemap;
use App\Models\Import;
use App\Providers\SeoServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point that asks for a sitemap rebuild after a change that decides what a guest sees (CG-15).
 * Used by the model listeners ({@see SeoServiceProvider}) and by bulk actions that write through
 * query builder updates without model events. {@see GenerateSitemap} is unique until processing, so a burst of
 * requests collapses into one queued job.
 */
class SitemapRebuild
{
    public const PENDING_KEY = 'sitemap:rebuild-pending';

    /**
     * Queue a rebuild after the current transaction commits. While an import runs nothing is queued, only a
     * «pending» flag is set: {@see afterImport()} rebuilds once when the import reaches a final state, instead of
     * after every chunk.
     */
    public static function request(): void
    {
        if (config('shop.sitemap_rebuild_on_change') !== true) {
            return;
        }

        if (Import::anyRunning()) {
            Cache::put(self::PENDING_KEY, true, now()->addDay());

            return;
        }

        DB::afterCommit(static fn () => GenerateSitemap::dispatch());
    }

    /**
     * An import reached a final state. A completed one changed the catalog, so it rebuilds; a failed or cancelled one
     * rebuilds only when a request was suppressed meanwhile. One dispatch at most, the flag is cleared.
     */
    public static function afterImport(Import $import): void
    {
        $pending = Cache::pull(self::PENDING_KEY) === true;
        $completed = in_array($import->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true);

        if ($completed || $pending) {
            GenerateSitemap::dispatch();
        }
    }
}
