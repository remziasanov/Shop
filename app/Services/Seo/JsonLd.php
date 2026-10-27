<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * Safe JSON for <script type="application/ld+json">: `<`, `>` and `&` are hex-escaped so no value can close the script tag.
 */
final class JsonLd
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
