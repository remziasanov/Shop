<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Support\Str;

/**
 * Immutable `code` of delivery and payment methods (CG-09). A code is generated from the name (transliterated slug,
 * numeric suffix on collisions) when a method is created; it never changes when the method is renamed.
 */
final class MethodCodes
{
    /**
     * Fills missing codes and repairs duplicated ones (a copied repeater row), keeping existing unique codes.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function ensure(array $rows): array
    {
        $rows = array_values($rows);
        $used = [];

        foreach ($rows as $index => $row) {
            $code = $row['code'] ?? null;

            if (is_string($code) && $code !== '' && ! isset($used[$code])) {
                $used[$code] = true;
            } else {
                $rows[$index]['code'] = null;
            }
        }

        foreach ($rows as $index => $row) {
            if ($row['code'] === null) {
                $rows[$index]['code'] = self::unique(self::base((string) ($row['name'] ?? '')), $used);
                $used[$rows[$index]['code']] = true;
            }
        }

        return $rows;
    }

    /**
     * Codes of the rows, in order, without changing the settings: stored codes, generated ones for rows that have none yet.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<string>
     */
    public static function codesOf(array $rows): array
    {
        return array_map(fn (array $row): string => (string) $row['code'], self::ensure($rows));
    }

    private static function base(string $name): string
    {
        return Str::slug($name) ?: 'method';
    }

    /**
     * @param  array<string, true>  $used
     */
    private static function unique(string $base, array $used): string
    {
        $code = $base;
        $suffix = 2;

        while (isset($used[$code])) {
            $code = $base.'-'.$suffix++;
        }

        return $code;
    }
}
