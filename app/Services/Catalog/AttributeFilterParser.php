<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Exceptions\TooManyFilterParameters;
use App\Models\AttributeValue;

/**
 * Reads `a[slug][]=value-slug`, `a[slug][min|max]=` and `a[slug]=1` (FL-07) against the effective filter set of a
 * category. Everything is whitelisted: characteristics outside the set (inactive, not filterable, unknown), unknown
 * value slugs, values of the wrong shape and empty ranges are dropped, never reported. At most
 * {@see MAX_VALUES} values per characteristic are read; more than {@see MAX_FILTERS} filled characteristics answer 404
 * (TZ 20.7). The result is in set order, so one selection always yields one URL and one cache key.
 */
final class AttributeFilterParser
{
    public const MAX_FILTERS = 10;

    public const MAX_VALUES = 20;

    /** Largest absolute value of decimal(14,4) as the integer part (ten digits) and as a whole number. */
    private const MAX_INTEGER_DIGITS = 10;

    private const MAX_NUMBER = '9999999999.9999';

    public function __construct(private readonly StorefrontFilterSets $sets) {}

    /**
     * @param  array<string, mixed>  $query  the request's query string
     * @param  ResolvedFilterSet|null  $set  the storefront set of the category when the caller already has it (saves a query)
     *
     * @throws TooManyFilterParameters
     */
    public function parse(array $query, int $categoryId, ?ResolvedFilterSet $set = null): AttributeSelection
    {
        $raw = $query['a'] ?? null;

        if (! is_array($raw) || $raw === []) {
            return new AttributeSelection;
        }

        // Counted before the whitelist: unknown slugs count too. Empty inputs of the form do not.
        $filled = 0;

        foreach ($raw as $value) {
            if (self::isFilled($value)) {
                $filled++;
            }
        }

        if ($filled > self::MAX_FILTERS) {
            throw new TooManyFilterParameters;
        }

        $set ??= $this->sets->for($categoryId);

        if ($set->isEmpty()) {
            return new AttributeSelection;
        }

        /** @var array<int, list<string>> $wanted */
        $wanted = [];
        $candidates = [];

        foreach ($set->rows as $row) {
            $attribute = $row->attribute;

            if ($attribute === null || ! isset($raw[$attribute->slug])) {
                continue;
            }

            $filter = AttributeFilter::for($attribute);
            $value = $raw[$attribute->slug];

            if ($attribute->type === AttributeType::List) {
                $slugs = $this->slugs($value);

                if ($slugs !== []) {
                    $wanted[$attribute->id] = $slugs;
                    $candidates[] = $filter;
                }
            } elseif ($attribute->type === AttributeType::Number) {
                $candidates[] = $this->range($filter, $value);
            } elseif ((is_string($value) || is_int($value)) && (string) $value === '1') {
                $candidates[] = $filter->withEnabled(true);
            }
        }

        $dictionary = $this->dictionary($wanted);
        $filters = [];

        foreach ($candidates as $filter) {
            if ($filter->type === AttributeType::List) {
                $filter = $filter->withValues($dictionary[$filter->attributeId] ?? []);
            }

            if ($filter->isActive()) {
                $filters[] = $filter;
            }
        }

        return new AttributeSelection($filters);
    }

    private static function isFilled(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::isFilled($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }

    /**
     * @return list<string>
     */
    private function slugs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $slugs = [];

        foreach ($value as $item) {
            if ((is_string($item) || is_int($item)) && trim((string) $item) !== '' && mb_strlen((string) $item) <= 190) {
                $slugs[(string) $item] = true;
            }
        }

        return array_slice(array_map('strval', array_keys($slugs)), 0, self::MAX_VALUES);
    }

    /**
     * One query for the values of all list filters: unknown slugs vanish here. Dictionary order.
     *
     * @param  array<int, list<string>>  $wanted
     * @return array<int, list<array{id: int, slug: string, label: string, sort: int}>>
     */
    private function dictionary(array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }

        $rows = AttributeValue::query()
            ->whereIn('attribute_id', array_keys($wanted))
            ->whereIn('slug', array_merge(...array_values($wanted)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'attribute_id', 'value', 'slug', 'sort_order']);

        $values = [];

        foreach ($rows as $row) {
            if (in_array($row->slug, $wanted[$row->attribute_id] ?? [], true)) {
                $values[$row->attribute_id][] = ['id' => $row->id, 'slug' => $row->slug, 'label' => $row->value, 'sort' => $row->sort_order];
            }
        }

        return $values;
    }

    private function range(AttributeFilter $filter, mixed $value): AttributeFilter
    {
        if (! is_array($value)) {
            return $filter;
        }

        $min = $this->decimal($value['min'] ?? null);
        $max = $this->decimal($value['max'] ?? null);

        if ($min !== null && $max !== null && bccomp($min, $max, 4) > 0) {
            [$min, $max] = [$max, $min];
        }

        return $filter->withRange($min, $max);
    }

    /**
     * A plain decimal with at most four fraction digits, clamped to the range of decimal(14,4); null when it is not
     * a number. «1 200,5» style input is not accepted: the form sends plain numbers.
     */
    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = str_replace(',', '.', trim((string) $value));

        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $text, $m) !== 1) {
            return null;
        }

        $integer = ltrim($m[2], '0');

        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            return $m[1] === '-' ? '-'.self::MAX_NUMBER : self::MAX_NUMBER;
        }

        $number = ($integer === '' ? '0' : $integer).'.'.substr(($m[3] ?? '').'0000', 0, 4);

        return AttributeValueParser::formatNumber($m[1].$number);
    }
}
