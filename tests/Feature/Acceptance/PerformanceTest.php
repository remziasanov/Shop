<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/home.php';

/**
 * NF-02 / NF-03: the storefront stays within 30 SQL queries per page on a demo-sized catalog (cold and warm
 * cache), nothing is lazy loaded, and every <img> is sized and lazy (except the first screen).
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    // Real photo rows for a part of the catalog: listing and gallery code paths run with images.
    Product::query()->limit(30)->get()->each(fn (Product $p) => ProductImage::factory()->count(2)->create(['product_id' => $p->id]));

    storeHomeBlocks([
        homeBlock('banner', ['image' => bannerImage()]),
        homeBlock('categories', ['mode' => 'auto', 'max_count' => 8]),
        homeBlock('products', ['source' => 'hits', 'limit' => 12, 'sort' => 'default']),
        homeBlock('products', ['source' => 'new', 'limit' => 12, 'sort' => 'default']),
        homeBlock('advantages', ['items' => [['icon' => 'star', 'title' => 'Доставка']]]),
    ]);

    Page::factory()->create(['slug' => 'o-nas', 'title' => 'О нас', 'content' => '<p>Текст</p>', 'is_active' => true]);
});

/**
 * @return array<string, string>
 */
function performanceUrls(): array
{
    $product = Product::query()->active()->has('images')->firstOrFail();

    return [
        'home' => '/',
        'catalog' => '/catalog',
        'category' => '/catalog/demo-elektronika',
        'category page 2, sorted' => '/catalog/demo-elektronika?page=2&sort=price_asc',
        'product' => '/product/'.$product->slug,
        'search' => '/search?q='.rawurlencode('зарядная'),
        'search suggest' => '/search/suggest?q='.rawurlencode('зарядная'),
        'cart' => '/cart',
        'cms page' => '/page/o-nas',
    ];
}

it('prevents lazy loading outside production (NF-02)', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();
});

it('stays within 30 queries per storefront page (NF-02)', function (): void {
    foreach (performanceUrls() as $name => $url) {
        Cache::flush();

        foreach (['cold', 'warm'] as $pass) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->get($url)->assertOk();

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            expect($count)->toBeLessThanOrEqual(30, "{$name} ({$pass}) ran {$count} queries");
        }
    }
});

it('sizes every image and lazy loads all but the first screen (NF-03)', function (): void {
    foreach (['/catalog', '/catalog/demo-elektronika', '/search?q='.rawurlencode('зарядная'), performanceUrls()['product'], '/'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all('/<img\b[^>]*>/i', $html, $matches);

        $eager = 0;

        foreach ($matches[0] as $tag) {
            // Images that the shop owner controls in the header (logo) are exempt from lazy loading: they are on the first screen.
            if (str_contains($tag, 'class="h-8 w-auto"')) {
                continue;
            }

            expect($tag)->toMatch('/\swidth="\d+"/', "{$url}: missing width in {$tag}")
                ->and($tag)->toMatch('/\sheight="\d+"/', "{$url}: missing height in {$tag}")
                ->and($tag)->toMatch('/\salt="/', "{$url}: missing alt in {$tag}");

            if (! str_contains($tag, 'loading="lazy"')) {
                $eager++;
            }
        }

        // The first row of a listing (eager) or the main gallery picture / first banner: a handful of images at most.
        expect($eager)->toBeLessThanOrEqual(6, "{$url}: {$eager} eager images");
    }
});

it('offers 400/800/1600 srcset variants on the product page (NF-03)', function (): void {
    $html = $this->get(performanceUrls()['product'])->assertOk()->getContent();

    expect($html)->toMatch('/srcset="[^"]*400w[^"]*800w[^"]*1600w/');
});

it('keeps storefront CSS and JS within 150 KB gzipped, Livewire included (NF-03)', function (): void {
    $manifestPath = public_path('build/manifest.json');
    $livewire = base_path('vendor/livewire/livewire/dist/livewire.min.js');

    if (! is_file($manifestPath) || ! is_file($livewire)) {
        $this->markTestSkipped('Run npm run build first.');
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $total = strlen((string) gzencode((string) file_get_contents($livewire), 9));

    foreach ($manifest as $entry) {
        foreach (array_merge([$entry['file']], $entry['css'] ?? []) as $file) {
            if (preg_match('/\.(css|js)$/', $file) === 1 && is_file(public_path('build/'.$file))) {
                $total += strlen((string) gzencode((string) file_get_contents(public_path('build/'.$file)), 9));
            }
        }
    }

    expect($total)->toBeLessThanOrEqual(150 * 1024);
});

it('stays cacheable with php artisan optimize: no closure routes, no env() outside config (NF-04)', function (): void {
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $action = $route->getAction('uses');

        // Closures of vendor packages (Livewire assets, the storage route) are serialized by Laravel itself.
        if ($action instanceof Closure && ! str_contains((string) (new ReflectionFunction($action))->getFileName(), '/vendor/')) {
            $this->fail('Closure route: '.$route->uri());
        }
    }

    $offenders = [];

    foreach ([app_path(), base_path('routes'), base_path('bootstrap/app.php'), database_path()] as $path) {
        $files = is_file($path)
            ? [$path]
            : array_map(fn (SplFileInfo $f) => $f->getPathname(), iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))));

        foreach ($files as $file) {
            if (str_ends_with($file, '.php') && preg_match('/\benv\(/', (string) file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }
    }

    expect($offenders)->toBe([]);
});
