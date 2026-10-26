<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unique, transliterated slugs (SF-80): "Кресло офисное" => "kreslo-ofisnoe", then "-2", "-3" on collision.
 */
final class SlugService
{
    /** The column is varchar(255); keep room for a numeric suffix. */
    public const MAX_LENGTH = 240;

    /**
     * Slug for a record: the manual slug if given, otherwise generated from the name.
     * Either way it is normalized and made unique in the table (soft-deleted rows included).
     */
    public function resolve(string $table, ?string $manualSlug, string $name, ?int $ignoreId = null): string
    {
        $manualSlug = $manualSlug !== null ? trim($manualSlug) : '';

        return $this->unique($table, $manualSlug !== '' ? $manualSlug : $name, $ignoreId);
    }

    /**
     * Unique slug generated from $source (a name or a manually typed slug).
     *
     * @param  string  $table  `products` or `categories` (any table with a `slug` column)
     * @param  int|null  $ignoreId  id of the record being edited, so it does not collide with itself
     */
    public function unique(string $table, string $source, ?int $ignoreId = null): string
    {
        $base = $this->normalize($source);

        if ($base === '') {
            $base = $table === 'categories' ? 'category' : 'item';
        }

        $taken = DB::table($table)
            ->where(function ($query) use ($base): void {
                $query->where('slug', $base)
                    // A normalized slug contains only [a-z0-9-], so it has no LIKE wildcards to escape.
                    ->orWhere('slug', 'like', $base.'-%');
            })
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $taken = array_flip($taken);
        $suffix = 2;

        while (isset($taken[$base.'-'.$suffix])) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    /**
     * Normalize text to slug form with the Russian transliteration table.
     */
    public function normalize(string $text): string
    {
        return rtrim(Str::limit(Str::slug($text, '-', 'ru'), self::MAX_LENGTH, ''), '-');
    }
}
