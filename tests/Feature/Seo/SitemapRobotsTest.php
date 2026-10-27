<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Jobs\GenerateSitemap;
use App\Models\Category;
use App\Models\Import;
use App\Models\Page;
use App\Models\Product;
use App\Services\Seo\SitemapGenerator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    config(['app.url' => 'https://shop.example']);
    $this->dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    config(['shop.sitemap_dir' => $this->dir]);
    Page::query()->delete(); // the shipped default pages (D-058) are not part of these counts
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('lists the home page, catalog, visible categories, active products and active pages (SF-82)', function () {
    $visible = Category::factory()->create(['slug' => 'lampy']);
    $hidden = Category::factory()->inactive()->create(['slug' => 'skrytye']);
    $shown = Product::factory()->inCategory($visible)->create(['slug' => 'lampa-1']);
    $inactive = Product::factory()->inCategory($visible)->inactive()->create(['slug' => 'lampa-off']);
    $inHidden = Product::factory()->inCategory($hidden)->create(['slug' => 'v-skrytoj']);
    Page::factory()->create(['slug' => 'delivery']);
    Page::factory()->inactive()->create(['slug' => 'draft']);

    $count = app(SitemapGenerator::class)->generate();
    $xml = file_get_contents($this->dir.'/sitemap.xml');

    expect($count)->toBe(5)
        ->and($xml)->toContain('<loc>https://shop.example/</loc>')
        ->and($xml)->toContain('<loc>https://shop.example/catalog</loc>')
        ->and($xml)->toContain('<loc>https://shop.example/catalog/lampy</loc>')
        ->and($xml)->toContain('<loc>https://shop.example/product/lampa-1</loc>')
        ->and($xml)->toContain('<loc>https://shop.example/page/delivery</loc>')
        ->and($xml)->toContain('<lastmod>')
        ->and($xml)->not->toContain('skrytye')
        ->and($xml)->not->toContain('lampa-off')
        ->and($xml)->not->toContain('v-skrytoj')
        ->and($xml)->not->toContain('draft');

    expect(simplexml_load_string($xml))->not->toBeFalse();
});

it('writes the file atomically without leaving temporary files (SF-82)', function () {
    file_put_contents($this->dir.'/sitemap.xml', 'OLD');
    Product::factory()->create();

    app(SitemapGenerator::class)->generate();

    expect(file_get_contents($this->dir.'/sitemap.xml'))->toContain('<urlset')
        ->and(glob($this->dir.'/.sitemap-*'))->toBe([]);
});

it('splits into an index and parts above the URL limit (SF-82)', function () {
    $category = Category::factory()->create();
    Product::factory()->count(4)->inCategory($category)->create();

    // home + catalog + category + 4 products = 7 URLs, 3 per part.
    $count = app(SitemapGenerator::class)->generate($this->dir, 3);
    $index = simplexml_load_string(file_get_contents($this->dir.'/sitemap.xml'));

    expect($count)->toBe(7)
        ->and($index->getName())->toBe('sitemapindex')
        ->and(count($index->sitemap))->toBe(3)
        ->and((string) $index->sitemap[0]->loc)->toBe('https://shop.example/sitemap-1.xml')
        ->and(file_exists($this->dir.'/sitemap-3.xml'))->toBeTrue();

    // Fewer URLs again: back to a single file and the stale parts are gone.
    app(SitemapGenerator::class)->generate($this->dir, 100);

    expect(file_exists($this->dir.'/sitemap-1.xml'))->toBeFalse()
        ->and(simplexml_load_string(file_get_contents($this->dir.'/sitemap.xml'))->getName())->toBe('urlset');
});

it('runs through shop:sitemap and is scheduled daily at 04:20 without overlapping (SF-82)', function () {
    $this->artisan('shop:sitemap')->assertSuccessful();

    expect(file_exists($this->dir.'/sitemap.xml'))->toBeTrue();

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'shop:sitemap'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});

it('generates the sitemap on the fly when the file is missing (SF-82)', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/xml')
        ->and(file_exists($this->dir.'/sitemap.xml'))->toBeTrue();
});

it('queues sitemap generation when an import finishes successfully (SF-82)', function () {
    Queue::fake();

    ImportFinished::dispatch(Import::factory()->status(ImportStatus::Completed)->create());
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    Cache::flush();
    ImportFinished::dispatch(Import::factory()->status(ImportStatus::CompletedWithErrors)->create());
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    Cache::flush();
    ImportFinished::dispatch(Import::factory()->status(ImportStatus::Failed)->create());
    Queue::assertNotPushed(GenerateSitemap::class);
});

it('serves robots.txt from the settings with the sitemap link and the admin path (SF-82)', function () {
    seoSettings(['robots_txt' => "User-agent: *\nDisallow: /secret"]);
    config(['shop.admin_path' => 'panel-x']);

    $text = $this->get('/robots.txt')->assertOk()->getContent();

    expect($text)->toContain('Disallow: /secret')
        ->and($text)->toContain('Disallow: /panel-x')
        ->and($text)->toContain('Disallow: /order/')
        ->and($text)->toContain('Sitemap: https://shop.example/sitemap.xml');
    expect(file_exists(public_path('robots.txt')))->toBeFalse();
});

it('falls back to the default robots rules when the setting is empty', function () {
    seoSettings(['robots_txt' => '']);

    $text = $this->get('/robots.txt')->getContent();

    expect($text)->toContain('Disallow: /cart')->toContain('Disallow: /checkout')->toContain('Disallow: /search')->toContain('Disallow: /admin');
});

it('does not generate in parallel: the locked job and command skip, the route answers 503 (SF-82, D-056)', function () {
    $lock = Cache::lock(SitemapGenerator::LOCK, 60);
    expect($lock->get())->toBeTrue();

    expect(app(SitemapGenerator::class)->generateLocked())->toBeNull();

    (new GenerateSitemap)->handle(app(SitemapGenerator::class));
    $this->artisan('shop:sitemap')->assertSuccessful();
    expect(file_exists($this->dir.'/sitemap.xml'))->toBeFalse();

    $this->get('/sitemap.xml')->assertStatus(503)->assertHeader('Retry-After');
    expect(file_exists($this->dir.'/sitemap.xml'))->toBeFalse();

    $lock->release();

    expect(app(SitemapGenerator::class)->generateLocked())->toBeInt()
        ->and(file_exists($this->dir.'/sitemap.xml'))->toBeTrue();

    // The lock is released after a run.
    expect(Cache::lock(SitemapGenerator::LOCK, 5)->get())->toBeTrue();
});

it('releases the queued job back when the lock is busy, so it runs again later (SF-82)', function () {
    $lock = Cache::lock(SitemapGenerator::LOCK, 60);
    expect($lock->get())->toBeTrue();

    $job = new class extends GenerateSitemap
    {
        public ?int $releasedFor = null;

        public function release($delay = 0)
        {
            $this->releasedFor = (int) $delay;
        }
    };

    $job->handle(app(SitemapGenerator::class));
    expect($job->releasedFor)->toBe(120);

    $lock->release();
    $job->releasedFor = null;
    $job->handle(app(SitemapGenerator::class));

    expect($job->releasedFor)->toBeNull()->and(file_exists($this->dir.'/sitemap.xml'))->toBeTrue();
});

it('retries the sitemap job with a growing backoff after an exception (SF-82)', function () {
    expect((new GenerateSitemap)->backoff)->toBe([60, 120, 300, 600])
        ->and((new GenerateSitemap)->tries)->toBe(5);
});

it('holds the sitemap cache lock for sitemap_lock_seconds (D-056)', function () {
    expect(config('shop.sitemap_lock_seconds'))->toBe(300);

    config(['shop.sitemap_lock_seconds' => 60]);
    $busyAt59 = $busyAt61 = null;
    Product::factory()->create();
    $probed = false;
    DB::listen(function () use (&$busyAt59, &$busyAt61, &$probed): void {
        if ($probed) {
            return;
        }

        $probed = true;
        $this->travel(59)->seconds();
        $busyAt59 = ! Cache::lock(SitemapGenerator::LOCK, 5)->get();
        $this->travel(2)->seconds();
        $busyAt61 = ! Cache::lock(SitemapGenerator::LOCK, 5)->get();
    });
    expect(app(SitemapGenerator::class)->generateLocked())->toBeInt()
        ->and($busyAt59)->toBeTrue()
        ->and($busyAt61)->toBeFalse();
});

it('skips generation when the file guard is held even though the cache lock was dropped (D-056)', function () {
    $path = storage_path('framework/sitemap.lock');
    $other = fopen($path, 'c');
    expect(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();

    expect(app(SitemapGenerator::class)->generateLocked())->toBeNull()
        ->and(file_exists($this->dir.'/sitemap.xml'))->toBeFalse();
    // The cache lock is released again.
    expect(Cache::lock(SitemapGenerator::LOCK, 5)->get())->toBeTrue();
    Cache::lock(SitemapGenerator::LOCK)->forceRelease();

    flock($other, LOCK_UN);
    fclose($other);

    expect(app(SitemapGenerator::class)->generateLocked())->toBeInt();
});

it('uses a unique temporary file per generation, so concurrent runs cannot corrupt the result (D-056)', function () {
    Product::factory()->count(3)->create();
    $generator = app(SitemapGenerator::class);

    // Two unlocked runs back to back (the worst case of a dropped lock) leave one valid file and no temp leftovers.
    $generator->generate();
    $generator->generate();

    expect(simplexml_load_file($this->dir.'/sitemap.xml'))->not->toBeFalse()
        ->and(glob($this->dir.'/.sitemap-*'))->toBe([]);

    // tempnam never returns the same name twice, even for the same directory.
    $method = new ReflectionMethod($generator, 'tmpPath');
    $first = $method->invoke($generator, $this->dir);
    $second = $method->invoke($generator, $this->dir);
    expect($first)->not->toBe($second);
    @unlink($first);
    @unlink($second);
});
