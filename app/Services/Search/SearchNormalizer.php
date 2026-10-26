<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Text normalization shared by `products.search_text` and search queries (SF-20, D-019):
 * lower case, "ё" => "е", single spaces, trimmed.
 */
final class SearchNormalizer
{
    /** Length of products.search_text. */
    public const MAX_LENGTH = 1000;

    /** Escape character used in `LIKE ? ESCAPE '!'` (portable across SQLite and MySQL). */
    public const LIKE_ESCAPE = '!';

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Distinct words of a normalized text.
     *
     * @return list<string>
     */
    public function words(string $text): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * Escape LIKE wildcards so user input is matched literally (use with `ESCAPE '!'`).
     */
    public function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Build `products.search_text` from name, SKU and brand, truncated multibyte-safe.
     */
    public function buildSearchText(string ...$parts): string
    {
        $text = $this->normalize(implode(' ', array_filter($parts, fn (string $part): bool => $part !== '')));

        return rtrim(mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8'));
    }
}
