<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

/**
 * Recognizes photo references that point to this site's own public storage (EX-01: the export writes
 * `{APP_URL}/storage/products/{id}/{file}`), so an export → import round trip does not download them again (D-040).
 *
 * A reference is "own" when it is a relative path under the public disk URL path (e.g. `/storage/products/7/x.webp`)
 * or an absolute URL whose host is the host of `APP_URL` (or of the public disk URL) with such a path.
 */
final class OwnMediaLocator
{
    /**
     * Path of the reference on the public disk (`products/7/x-1600.webp`), or null when it is not an own reference.
     */
    public function relativePath(string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '' || preg_match('/[\x00-\x20\x7f]/', $reference) === 1) {
            return null;
        }

        $parts = parse_url($reference);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['host'])) {
            if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! in_array(strtolower($parts['host']), $this->ownHosts(), true)) {
                return null;
            }
        } elseif (isset($parts['scheme'])) {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '');
        $prefix = $this->pathPrefix();

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        $relative = substr($path, strlen($prefix));

        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            return null;
        }

        return $relative;
    }

    /**
     * The product image whose large, medium or small file the reference points to.
     */
    public function productImage(string $reference): ?ProductImage
    {
        $relative = $this->relativePath($reference);

        if ($relative === null || preg_match('#^products/(\d+)/[^/]+$#', $relative, $match) !== 1) {
            return null;
        }

        return ProductImage::query()
            ->where('product_id', (int) $match[1])
            ->where(fn ($query) => $query->where('path_large', $relative)->orWhere('path_medium', $relative)->orWhere('path_small', $relative))
            ->first();
    }

    /**
     * Absolute path of an own file on the public disk (null when the disk is not local or the file is missing).
     */
    public function absolutePath(string $relative): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($relative)) {
            return null;
        }

        try {
            $path = $disk->path($relative);
        } catch (\Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * @return list<string> lower-cased hosts that mean "this site"
     */
    private function ownHosts(): array
    {
        $hosts = [];

        foreach ([(string) config('app.url'), Storage::disk('public')->url('')] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return $hosts;
    }

    private function pathPrefix(): string
    {
        $path = parse_url(Storage::disk('public')->url(''), PHP_URL_PATH);
        $path = is_string($path) ? rtrim($path, '/') : '';

        return ($path === '' ? '/storage' : $path).'/';
    }
}
