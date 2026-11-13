<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\FilterSource;
use App\Exceptions\TooManyFilterParameters;
use App\Models\OptionValue;

/**
 * Reads `o[slug][]=value-slug` and `o_available=1` (VR-19) against the effective filter set of a category, with the
 * rules of {@see AttributeFilterParser}: everything is whitelisted (options outside the set, unknown value slugs and
 * values of the wrong shape are dropped, never reported), at most {@see AttributeFilterParser::MAX_VALUES} values per
 * option, and more than {@see AttributeFilterParser::MAX_FILTERS} filled `a` and `o` entries together answer 404. The
 * result is in set order, so one selection always yields one URL and one cache key.
 */
final class OptionFilterParser
{
    public function __construct(private readonly StorefrontFilterSets $sets) {}

    /**
     * @param  array<string, mixed>  $query  the request's query string
     * @param  ResolvedFilterSet|null  $set  the storefront set of the category when the caller already has it (saves a query)
     *
     * @throws TooManyFilterParameters
     */
    public function parse(array $query, int $categoryId, ?ResolvedFilterSet $set = null): OptionSelection
    {
        $raw = $query['o'] ?? null;
        $raw = is_array($raw) ? $raw : [];
        $availableRequested = isset($query[OptionSelection::AVAILABLE_PARAM]) && (is_string($query[OptionSelection::AVAILABLE_PARAM]) || is_int($query[OptionSelection::AVAILABLE_PARAM])) && (string) $query[OptionSelection::AVAILABLE_PARAM] === '1';

        // Counted before the whitelist, together with the characteristics (FL-07, TZ 20.7).
        $filled = 0;

        foreach ([$raw, is_array($query['a'] ?? null) ? $query['a'] : []] as $group) {
            foreach ($group as $value) {
                if (self::isFilled($value)) {
                    $filled++;
                }
            }
        }

        if ($filled > AttributeFilterParser::MAX_FILTERS) {
            throw new TooManyFilterParameters;
        }

        if ($raw === [] && ! $availableRequested) {
            return new OptionSelection;
        }

        $set ??= $this->sets->for($categoryId);
        $rows = $set->rows->filter(fn ($row): bool => $row->source === FilterSource::Option && $row->optionType !== null);

        if ($rows->isEmpty()) {
            return new OptionSelection;
        }

        /** @var array<int, list<string>> $wanted */
        $wanted = [];
        $candidates = [];

        foreach ($rows as $row) {
            $type = $row->optionType;

            if ($type === null || ! isset($raw[$type->slug])) {
                continue;
            }

            $slugs = $this->slugs($raw[$type->slug]);

            if ($slugs !== []) {
                $wanted[$type->id] = $slugs;
                $candidates[] = new OptionFilter($type->id, $type->slug, $type->name, $type->display_type);
            }
        }

        $dictionary = $this->dictionary($wanted);
        $filters = [];

        foreach ($candidates as $filter) {
            $filter = $filter->withValues($dictionary[$filter->optionTypeId] ?? []);

            if ($filter->isActive()) {
                $filters[] = $filter;
            }
        }

        return new OptionSelection($filters, $availableRequested);
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

        return array_slice(array_map('strval', array_keys($slugs)), 0, AttributeFilterParser::MAX_VALUES);
    }

    /**
     * One query for the values of all options: unknown slugs vanish here. Dictionary order.
     *
     * @param  array<int, list<string>>  $wanted
     * @return array<int, list<array{id: int, slug: string, label: string, sort: int}>>
     */
    private function dictionary(array $wanted): array
    {
        if ($wanted === []) {
            return [];
        }

        $rows = OptionValue::query()
            ->whereIn('option_type_id', array_keys($wanted))
            ->whereIn('slug', array_merge(...array_values($wanted)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'option_type_id', 'value', 'slug', 'sort_order']);

        $values = [];

        foreach ($rows as $row) {
            if (in_array($row->slug, $wanted[$row->option_type_id] ?? [], true)) {
                $values[$row->option_type_id][] = ['id' => $row->id, 'slug' => $row->slug, 'label' => $row->value, 'sort' => $row->sort_order];
            }
        }

        return $values;
    }
}
