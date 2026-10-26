<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Settings\GeneralSettings;
use App\Settings\SeoSettings;

/**
 * Text of /robots.txt (SF-82): the text from SeoSettings plus the sitemap link; everything is disallowed while the shop is closed.
 */
final class RobotsBuilder
{
    public function __construct(
        private readonly SeoSettings $seo,
        private readonly GeneralSettings $general,
    ) {}

    public function build(): string
    {
        if (! $this->general->shop_open) {
            return "User-agent: *\nDisallow: /\n";
        }

        $admin = '/'.trim((string) config('shop.admin_path'), '/');
        $text = trim(str_replace("\r", '', $this->seo->robots_txt));

        if ($text === '') {
            $text = "User-agent: *\nDisallow: /cart\nDisallow: /checkout\nDisallow: /search";
        }

        // The admin path is configurable (ADMIN_PATH): always keep it disallowed, plus the order pages.
        foreach ([$admin, '/order/'] as $path) {
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
