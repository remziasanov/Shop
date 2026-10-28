<?php

declare(strict_types=1);

use App\Models\CustomerGroup;
use App\Services\Access\CatalogAccess;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Per-request memo of the catalog version and of the «Гость» group (CG-19): read once, replaced by bump(), dropped at
 * the start of a request and before each queued job.
 */
function guestQueries(): int
{
    return collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'customer_groups'))->count();
}

it('reads the catalog version from the cache once per request', function (): void {
    $first = CatalogVersion::current();
    Cache::forever(CatalogVersion::KEY, 'changed-elsewhere');

    expect(CatalogVersion::current())->toBe($first);

    CatalogVersion::reset();
    expect(CatalogVersion::current())->toBe('changed-elsewhere');
});

it('replaces the memo at once when the version is bumped', function (): void {
    $before = CatalogVersion::current();
    $after = CatalogVersion::bump();

    expect($after)->not->toBe($before)->and(CatalogVersion::current())->toBe($after);
});

it('loads the guest group once per catalog version and again after a change of the group', function (): void {
    $access = app(CatalogAccess::class);
    $access->reset();
    CatalogVersion::current();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $access->guestGroup();
    $access->guestGroup();
    app(ViewerContext::class)->group();

    expect(guestQueries())->toBe(1);

    guestGroup()->update(['name' => 'Гость 2']);
    DB::flushQueryLog();

    expect($access->guestGroup()->name)->toBe('Гость 2')->and(guestQueries())->toBe(1);
});

it('starts every request and every queued job without the previous memo', function (): void {
    $access = app(CatalogAccess::class);
    CatalogVersion::current();
    $access->guestGroup();
    Cache::forever(CatalogVersion::KEY, 'worker-sees-this');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);
    Event::dispatch(new JobProcessing('database', $job));
    expect(CatalogVersion::current())->toBe('worker-sees-this');

    Cache::forever(CatalogVersion::KEY, 'next-request');
    app(ViewerContext::class)->beginRequest();

    expect(CatalogVersion::current())->toBe('next-request')
        ->and($access->guestGroup())->toBeInstanceOf(CustomerGroup::class);
});
