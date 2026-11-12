<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;

/**
 * Chips of the active characteristic filters and the «Сбросить всё» URL (FL-13). Removing a chip keeps sort and the
 * built-in filters, drops the page.
 */
final class FilterChips
{
    /**
     * Text of a number range: «от 500 до 900 Вт».
     */
    public static function rangeText(AttributeFilter $filter): string
    {
        $unit = $filter->unit !== null && $filter->unit !== '' ? ' '.$filter->unit : '';

        return match (true) {
            $filter->min !== null && $filter->max !== null => __('storefront.filters.range_between', ['min' => $filter->min, 'max' => $filter->max]).$unit,
            $filter->min !== null => __('storefront.filters.range_from', ['value' => $filter->min]).$unit,
            default => __('storefront.filters.range_to', ['value' => (string) $filter->max]).$unit,
        };
    }

    /**
     * Text of what a filter selects, for chips and for the `{filters}` placeholder of SEO templates: values of a list
     * joined with a comma, a range, the name of a boolean.
     */
    public static function valueText(AttributeFilter $filter): string
    {
        return match ($filter->type) {
            AttributeType::List => implode(', ', array_map(fn (array $value): string => $value['label'], $filter->values)),
            AttributeType::Number => self::rangeText($filter),
            AttributeType::Boolean => $filter->name,
        };
    }

    /**
     * @param  array<string, mixed>  $extraQuery  hidden parameters of the page that stay in every URL
     * @return list<FilterChip>
     */
    public function build(CatalogFilters $filters, string $url, array $extraQuery = []): array
    {
        $chips = [];

        foreach ($filters->attributes->filters as $filter) {
            if ($filter->type === AttributeType::List) {
                foreach ($filter->values as $value) {
                    $label = $filter->name.': '.$value['label'];
                    $chips[] = new FilterChip($label, $this->url($url, $extraQuery, $filters->withAttributes($filters->attributes->without($filter->slug, $value['slug']))), __('storefront.filters.remove', ['filter' => $label]));
                }

                continue;
            }

            $label = $filter->type === AttributeType::Number ? $filter->name.': '.self::rangeText($filter) : $filter->name;
            $chips[] = new FilterChip($label, $this->url($url, $extraQuery, $filters->withAttributes($filters->attributes->without($filter->slug))), __('storefront.filters.remove', ['filter' => $label]));
        }

        return $chips;
    }

    /**
     * «Сбросить всё»: the page without any filter or sort; the hidden parameters of the page stay.
     *
     * @param  array<string, mixed>  $extraQuery
     */
    public function resetUrl(string $url, array $extraQuery = []): string
    {
        return $extraQuery === [] ? $url : $url.'?'.http_build_query($extraQuery);
    }

    /**
     * @param  array<string, mixed>  $extraQuery
     */
    private function url(string $url, array $extraQuery, CatalogFilters $filters): string
    {
        $query = $extraQuery + $filters->toQuery();

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
