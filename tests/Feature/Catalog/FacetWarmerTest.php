<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Jobs\WarmCategoryFacets;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Services\Access\CatalogAccess;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\FacetService;
use App\Services\Catalog\FacetWarmer;
use App\Services\Catalog\StorefrontFilterSets;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * FL-11, FL-12 (D-165): the facets of a category page without filters are built in the queue, so the first visitor
 * does not wait for them.
 */
beforeEach(function (): void {
    $this->withoutVite();
    attrMigrated();

    $this->category = Category::factory()->create(['slug' => 'warm']);
    $color = facetAttr('color');
    $red = facetValue($color, 'red', 'Red');
    $product = Product::factory()->inCategory($this->category)->create();
    DB::table('product_attribute_values')->insert(['product_id' => $product->id, 'attribute_id' => $color->id, 'attribute_value_id' => $red->id, 'value_number' => null, 'value_bool' => null]);
    facetSet($this->category, [$color]);
    $this->color = $color;

    // The test catalog is tiny: warm every category unless a test sets its own limits.
    useWarmerLimits(1, FacetWarmer::MAX_CATEGORIES);
});

function useWarmerLimits(int $minProducts, int $maxCategories): void
{
    app()->bind(FacetWarmer::class, fn ($app): FacetWarmer => new FacetWarmer(
        $app->make(ViewerContext::class),
        $app->make(CatalogAccess::class),
        $app->make(CategoryTree::class),
        $app->make(StorefrontFilterSets::class),
        $app->make(FacetService::class),
        $app->make(CatalogSettings::class),
        $minProducts,
        $maxCategories,
    ));
}

/** Sets the catalog version to a token of the given age. */
function versionAged(int $seconds): string
{
    $token = (string) ((now()->getTimestamp() - $seconds) * 1_000_000);
    Cache::forever(CatalogVersion::KEY, $token);
    CatalogVersion::reset();

    return $token;
}

function lastPass(string $version, int $secondsAgo): void
{
    Cache::put(FacetWarmer::LAST_PASS_KEY, ['version' => $version, 'at' => now()->getTimestamp() - $secondsAgo], 3600);
}

function facetQueriesOf(string $url): int
{
    $count = 0;

    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'product_attribute_values') && str_contains(strtolower($query->sql), 'group by')) {
            $count++;
        }
    });

    test()->get($url)->assertOk();

    return $count;
}

it('builds the unfiltered facets so that the page reads them from the cache', function (): void {
    expect(app(FacetWarmer::class)->warm())->toBeNull();

    expect(facetQueriesOf(route('catalog.category', 'warm')))->toBe(0);
});

it('leaves the facets cold without warming (the control of the test above)', function (): void {
    expect(facetQueriesOf(route('catalog.category', 'warm')))->toBeGreaterThan(0);
});

it('stops at the budget and continues from the returned offset', function (): void {
    $warmer = app(FacetWarmer::class);

    expect($warmer->warm(0, 0.0))->toBe(0)
        ->and($warmer->warm(0, 30.0))->toBeNull();
});

it('does not warm categories the group cannot see, nor when the facet cache is off', function (): void {
    $this->category->update(['is_active' => false]);
    expect(app(FacetWarmer::class)->pairs())->toBe([]);

    $this->category->update(['is_active' => true]);
    $settings = app(CatalogSettings::class);
    $settings->facets_cache_minutes = 0;
    $settings->save();

    expect(app(FacetWarmer::class)->warm())->toBeNull()
        ->and(facetQueriesOf(route('catalog.category', 'warm')))->toBeGreaterThan(0);
});

it('queues one warming job from the command and the job warms the cache', function (): void {
    versionAged(600);
    Queue::fake();
    $this->artisan('shop:warm-facets')->assertSuccessful();
    Queue::assertPushed(WarmCategoryFacets::class, 1);

    app()->call([new WarmCategoryFacets, 'handle']);

    expect(facetQueriesOf(route('catalog.category', 'warm')))->toBe(0);
});

describe('start conditions of shop:warm-facets (D-165)', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        $this->version = versionAged(600);
    });

    it('queues a chain when the version changed and is settled, and marks the chain', function (): void {
        $this->artisan('shop:warm-facets')->assertSuccessful();

        Queue::assertPushed(WarmCategoryFacets::class, fn (WarmCategoryFacets $job): bool => $job->offset === 0 && $job->version === $this->version);
        expect(Cache::has(FacetWarmer::CHAIN_KEY))->toBeTrue();

        // The next minute nothing more is queued while the chain is in progress.
        $this->artisan('shop:warm-facets')->assertSuccessful();
        Queue::assertPushed(WarmCategoryFacets::class, 1);
    });

    it('queues a chain when the last pass of the same version is about to expire', function (): void {
        $minutes = app(CatalogSettings::class)->facets_cache_minutes;
        lastPass($this->version, ($minutes - 1) * 60 + 5);

        $this->artisan('shop:warm-facets')->assertSuccessful();

        Queue::assertPushed(WarmCategoryFacets::class, 1);
    });

    it('does nothing when the last pass of the same version is fresh', function (): void {
        lastPass($this->version, 60);

        $this->artisan('shop:warm-facets')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('does nothing while a chain is in progress', function (): void {
        Cache::put(FacetWarmer::CHAIN_KEY, true, 300);

        $this->artisan('shop:warm-facets')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('does nothing while an import runs, but ignores a stuck import', function (): void {
        $import = Import::factory()->create(['status' => ImportStatus::Processing]);

        $this->artisan('shop:warm-facets')->assertSuccessful();
        Queue::assertNothingPushed();

        Import::query()->whereKey($import->id)->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('shop:warm-facets')->assertSuccessful();
        Queue::assertPushed(WarmCategoryFacets::class, 1);
    });

    it('does nothing while the catalog version is younger than three minutes', function (): void {
        versionAged(60);

        $this->artisan('shop:warm-facets')->assertSuccessful();
        Queue::assertNothingPushed();

        versionAged(FacetWarmer::SETTLE_SECONDS + 5);

        $this->artisan('shop:warm-facets')->assertSuccessful();
        Queue::assertPushed(WarmCategoryFacets::class, 1);
    });

    it('treats an unparsable version token as settled', function (): void {
        expect(app(FacetWarmer::class)->isSettled('not-a-timestamp'))->toBeTrue();
    });

    it('does nothing when the facet cache is off', function (): void {
        $settings = app(CatalogSettings::class);
        $settings->facets_cache_minutes = 0;
        $settings->save();

        $this->artisan('shop:warm-facets')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('runs only a few cheap queries when there is nothing to do', function (): void {
        lastPass($this->version, 60);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->artisan('shop:warm-facets')->assertSuccessful();

        expect($queries)->toBeLessThanOrEqual(2);
    });
});

describe('the warming job (D-165)', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        $this->version = versionAged(600);
    });

    it('does nothing while the lock is held', function (): void {
        $lock = Cache::lock('shop:warm-facets', 60);
        expect($lock->get())->toBeTrue();

        app()->call([new WarmCategoryFacets(0, $this->version), 'handle']);

        Queue::assertNothingPushed();
        expect(Cache::has(FacetWarmer::LAST_PASS_KEY))->toBeFalse()
            ->and(facetQueriesOf(route('catalog.category', 'warm')))->toBeGreaterThan(0);

        $lock->release();
    });

    it('queues a continuation with the right offset when the budget is used up', function (): void {
        Cache::put(FacetWarmer::CHAIN_KEY, true, 300);

        app()->call([new WarmCategoryFacets(1, $this->version, 0.0), 'handle']);

        Queue::assertPushed(WarmCategoryFacets::class, fn (WarmCategoryFacets $job): bool => $job->offset === 1 && $job->version === $this->version);
        expect(Cache::has(FacetWarmer::CHAIN_KEY))->toBeTrue()
            ->and(Cache::has(FacetWarmer::LAST_PASS_KEY))->toBeFalse();
    });

    it('stops the chain when the catalog version changed meanwhile', function (): void {
        Cache::put(FacetWarmer::CHAIN_KEY, true, 300);
        $job = new WarmCategoryFacets(0, $this->version);

        CatalogVersion::bump();
        app()->call([$job, 'handle']);

        Queue::assertNothingPushed();
        expect(Cache::has(FacetWarmer::CHAIN_KEY))->toBeFalse()
            ->and(Cache::has(FacetWarmer::LAST_PASS_KEY))->toBeFalse();
    });

    it('records the completed pass at the end of the chain and releases the chain flag', function (): void {
        Cache::put(FacetWarmer::CHAIN_KEY, true, 300);

        app()->call([new WarmCategoryFacets(0, $this->version), 'handle']);

        Queue::assertNothingPushed();
        $last = Cache::get(FacetWarmer::LAST_PASS_KEY);
        expect(Cache::has(FacetWarmer::CHAIN_KEY))->toBeFalse()
            ->and($last['version'])->toBe($this->version)
            ->and($last['at'])->toBe(now()->getTimestamp())
            ->and(facetQueriesOf(route('catalog.category', 'warm')))->toBe(0);
    });
});

describe('which categories are warmed (D-165)', function (): void {
    it('uses the documented limits and skips small categories by default', function (): void {
        expect(FacetWarmer::MIN_PRODUCTS)->toBe(1000)->and(FacetWarmer::MAX_CATEGORIES)->toBe(100);

        useWarmerLimits(FacetWarmer::MIN_PRODUCTS, FacetWarmer::MAX_CATEGORIES);

        expect(app(FacetWarmer::class)->pairs())->toBe([]);
    });

    it('warms only categories with enough products, largest first, capped', function (): void {
        $sizes = ['big' => 5, 'mid' => 3, 'small' => 1, 'two' => 2];
        $ids = [];

        foreach ($sizes as $slug => $count) {
            $category = Category::factory()->create(['slug' => $slug]);
            Product::factory()->count($count)->inCategory($category)->create();
            facetSet($category, [$this->color]);
            $ids[$slug] = $category->id;
        }

        useWarmerLimits(2, 100);
        $order = fn (): array => array_values(array_unique(array_map(fn (array $pair): int => $pair[1], app(FacetWarmer::class)->pairs())));
        expect($order())->toBe([$ids['big'], $ids['mid'], $ids['two']]);

        useWarmerLimits(2, 2);
        expect($order())->toBe([$ids['big'], $ids['mid']]);
    });
});
