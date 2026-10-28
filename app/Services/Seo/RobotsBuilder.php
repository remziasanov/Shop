<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\StorefrontSection;
use App\Services\Access\CatalogAccess;
use App\Settings\SeoSettings;

/**
 * Text of /robots.txt (SF-82): the text from SeoSettings plus the sitemap link. The rules stay the same while the shop is closed (D-056): storefront pages
 * answer 503 + Retry-After, which crawlers treat as temporary, while `Disallow: /` could drop pages from the index.
 */
final class RobotsBuilder
{
    public function __construct(
        private readonly SeoSettings $seo,
        private readonly CatalogAccess $access,
    ) {}

    public function build(): string
    {
        $admin = '/'.trim((string) config('shop.admin_path'), '/');
        $text = trim(str_replace("\r", '', $this->seo->robots_txt));

        if ($text === '') {
            $text = "User-agent: *\nDisallow: /cart\nDisallow: /checkout\nDisallow: /search";
        }

        // The admin path is configurable (ADMIN_PATH): always keep it disallowed, plus the order pages.
        $paths = [$admin, '/order/'];

        // CG-15: built for the «Гость» group; when it has no catalog section, crawlers are told to stay out of it.
        if (! $this->access->guest()->allowsSection(StorefrontSection::Catalog)) {
            array_push($paths, '/catalog', '/product/');
        }

        foreach ($paths as $path) {
            if (preg_match('/^Disallow:\s*'.preg_quote($path, '/').'\s*$/mi', $text) !== 1) {
                $text = preg_match('/^User-agent:\s*\*\s*$/mi', $text) === 1
                    ? (string) preg_replace('/^(User-agent:\s*\*\s*)$/mi', "$1\nDisallow: ".$path, $text, 1)
                    : "User-agent: *\nDisallow: ".$path."\n\n".$text;
            }
        }

        if (preg_match('/^Sitemap:/mi', $text) !== 1) {
            $text .= "\n\nSitemap: ".rtrim((string) config('app.url'), '/').'/sitemap.xml';
        }

        return $text."\n";
    }
}
