<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use XMLWriter;

/**
 * Writes public/sitemap.xml (SF-82, D-056): home, catalog, visible categories, active products and active pages.
 * The XML is streamed with XMLWriter into a temporary file in the target directory and renamed over the old one,
 * so a crawler never sees a half-written file. With more than 50 000 URLs a sitemap index and parts are written.
 */
final class SitemapGenerator
{
    public const MAX_URLS = 50_000;

    public const LOCK = 'sitemap:generate';

    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(private readonly CategoryTree $tree) {}

    public static function directory(): string
    {
        $dir = config('shop.sitemap_dir');

        return is_string($dir) && $dir !== '' ? $dir : public_path();
    }

    /**
     * {@see generate()} under a cache lock, so the cron run, the post-import job and a crawler hitting a missing
     * file never write the sitemap in parallel (one process could remove the other's parts).
     *
     * @return int|null number of URLs written, null when another process is generating right now
     */
    public function generateLocked(?string $directory = null, int $maxUrls = self::MAX_URLS): ?int
    {
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->generate($directory, $maxUrls);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return int number of URLs written
     */
    public function generate(?string $directory = null, int $maxUrls = self::MAX_URLS): int
    {
        $directory = rtrim($directory ?? self::directory(), '/\\');

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Sitemap directory is not writable: '.$directory);
        }

        $base = rtrim((string) config('app.url'), '/');
        $tmpFiles = [];
        $total = 0;
        $inPart = 0;
        $writer = null;

        try {
            foreach ($this->entries() as [$path, $lastmod, $changefreq, $priority]) {
                if ($writer === null || $inPart >= $maxUrls) {
                    if ($writer !== null) {
                        $this->closeUrlset($writer);
                    }

                    $tmpFiles[] = $this->tmpPath($directory);
                    $writer = $this->openUrlset($tmpFiles[array_key_last($tmpFiles)]);
                    $inPart = 0;
                }

                $writer->startElement('url');
                $writer->writeElement('loc', $base.$path);

                if ($lastmod !== null) {
                    $writer->writeElement('lastmod', $lastmod->toAtomString());
                }

                $writer->writeElement('changefreq', $changefreq);
                $writer->writeElement('priority', $priority);
                $writer->endElement();

                $inPart++;
                $total++;
            }

            if ($writer !== null) {
                $this->closeUrlset($writer);
            }

            $this->publish($directory, $base, $tmpFiles);
        } catch (\Throwable $e) {
            foreach ($tmpFiles as $file) {
                @unlink($file);
            }

            throw $e;
        }

        return $total;
    }

    /**
     * @return Generator<int, array{0: string, 1: CarbonInterface|null, 2: string, 3: string}> path, lastmod, changefreq, priority
     */
    private function entries(): Generator
    {
        yield [route('home', [], false), null, 'daily', '1.0'];
        yield [route('catalog.index', [], false), null, 'daily', '0.9'];

        $visible = $this->tree->visibleCategoryIds();

        if ($visible !== []) {
            foreach (array_chunk($visible, 500) as $ids) {
                $categories = Category::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'slug', 'updated_at']);

                foreach ($categories as $category) {
                    yield [route('catalog.category', $category->slug, false), $category->updated_at, 'weekly', '0.8'];
                }
            }
        }

        $products = Product::query()->active()->select(['products.id', 'products.slug', 'products.updated_at']);

        foreach ($products->lazyById(1000, 'products.id', 'id') as $product) {
            yield [route('product.show', $product->slug, false), $product->updated_at, 'weekly', '0.6'];
        }

        // Pages are optional: the module may be absent or the route not registered.
        if (Route::has('page.show') && Schema::hasTable('pages')) {
            $pages = DB::table('pages')->where('is_active', true)->orderBy('id')->get(['slug', 'updated_at']);

            foreach ($pages as $page) {
                $updated = $page->updated_at !== null ? Carbon::parse($page->updated_at) : null;

                yield [route('page.show', [$page->slug], false), $updated, 'monthly', '0.4'];
            }
        }
    }

    private function openUrlset(string $file): XMLWriter
    {
        $writer = new XMLWriter;
        $writer->openUri($file);
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::NS);

        return $writer;
    }

    private function closeUrlset(XMLWriter $writer): void
    {
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    /**
     * @param  list<string>  $tmpFiles
     */
    private function publish(string $directory, string $base, array $tmpFiles): void
    {
        // The home page is always an entry, so there is at least one part.
        if (count($tmpFiles) === 1) {
            $this->rename($tmpFiles[0], $directory.'/sitemap.xml');
            $this->removeParts($directory, 0);

            return;
        }

        $index = $this->tmpPath($directory);
        $writer = new XMLWriter;
        $writer->openUri($index);
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', self::NS);

        foreach ($tmpFiles as $i => $file) {
            $name = 'sitemap-'.($i + 1).'.xml';
            $this->rename($file, $directory.'/'.$name);
            $writer->startElement('sitemap');
            $writer->writeElement('loc', $base.'/'.$name);
            $writer->writeElement('lastmod', now()->toAtomString());
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        $this->rename($index, $directory.'/sitemap.xml');
        $this->removeParts($directory, count($tmpFiles));
    }

    private function removeParts(string $directory, int $keep): void
    {
        foreach (glob($directory.'/sitemap-*.xml') ?: [] as $file) {
            if (preg_match('/sitemap-(\d+)\.xml$/', $file, $m) === 1 && (int) $m[1] > $keep) {
                @unlink($file);
            }
        }
    }

    private function tmpPath(string $directory): string
    {
        $path = tempnam($directory, '.sitemap-');

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary sitemap file in '.$directory);
        }

        return $path;
    }

    private function rename(string $from, string $to): void
    {
        @chmod($from, 0644);

        if (! @rename($from, $to)) {
            throw new RuntimeException('Cannot move the sitemap into place: '.$to);
        }
    }
}
