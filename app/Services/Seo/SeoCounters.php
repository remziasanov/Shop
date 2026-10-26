<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Settings\SeoSettings;

/**
 * Counter codes and verification tags from SeoSettings (SF-84). The codes are raw HTML that only an
 * administrator can set (the settings page is admin-only); verification tags are restricted to <meta> lines.
 */
final class SeoCounters
{
    public function __construct(private readonly SeoSettings $settings) {}

    public function headCode(): string
    {
        return trim((string) $this->settings->head_code);
    }

    public function bodyCode(): string
    {
        return trim((string) $this->settings->body_code);
    }

    /**
     * One <meta ...> tag per line; any other line is ignored.
     *
     * @return list<string>
     */
    public function verificationTags(): array
    {
        $tags = [];

        foreach (preg_split('/\R/u', (string) $this->settings->verification_meta) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^<meta\s[^<>]*>$/i', $line) === 1) {
                $tags[] = $line;
            }
        }

        return $tags;
    }
}
