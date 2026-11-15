<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\ImportStatus;
use App\Jobs\GenerateSitemap;
use App\Models\Import;
use App\Providers\SeoServiceProvider;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point that asks for a sitemap rebuild after a change that decides what a guest sees (CG-15).
 * Used by the model listeners ({@see SeoServiceProvider}) and by bulk actions that write through
 * query builder updates without model events. {@see GenerateSitemap} is unique until processing, so a burst of
 * requests collapses into one queued job.
 */
class SitemapRebuild
{
    /**
     * Queue a rebuild after the current transaction commits. While an import runs nothing is queued: the
     * ImportFinished listener rebuilds once at the end, instead of after every chunk.
     */
    public static function request(): void
    {
        if (config('shop.sitemap_rebuild_on_change') !== true || self::importRunning()) {
            return;
        }

        DB::afterCommit(static fn () => GenerateSitemap::dispatch());
    }

    private static function importRunning(): bool
    {
        $running = array_values(array_map(
            static fn (ImportStatus $status): string => $status->value,
            array_filter(ImportStatus::cases(), static fn (ImportStatus $status): bool => $status->isRunning()),
        ));

        return Import::query()->whereIn('status', $running)->exists();
    }
}
