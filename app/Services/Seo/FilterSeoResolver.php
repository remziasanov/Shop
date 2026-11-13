<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\FilterChips;
use App\Settings\CatalogSettings;

/**
 * FL-14: which category pages with characteristic filters search engines may index.
 *
 * (а) no characteristic filter: not handled here, the page keeps the behaviour of SF-13/SF-14.
 * (б) exactly one filter with exactly one value of a list characteristic with «индексировать страницы с одним
 *     значением», no sort, no built-in filter, first page: indexable, canonical to its own normalized URL, title and
 *     h1 «{категория} — {значение}», provided the page has at least `seo_single_value_min_products` products
 *     (the same threshold as the sitemap); with fewer it is `noindex,follow` like (в).
 * (в) anything else with characteristic filters (combinations, ranges, booleans, several values, sort, built-in
 *     filters, page > 1): `noindex,follow` and canonical to the category without parameters.
 */
final class FilterSeoResolver
{
    public function __construct(private readonly CatalogSettings $settings) {}

    /**
     * @param  int|null  $total  products of the page's selection (all pages); a single-value page with fewer than
     *                           `seo_single_value_min_products` is not indexable, like in the sitemap (FL-14 «б»)
     * @return FilterSeo|null null for (а)
     */
    public function resolve(Category $category, CatalogFilters $filters, int $page, ?int $total = null): ?FilterSeo
    {
        $selection = $filters->attributes;
        $options = $filters->options;

        if ($selection->isEmpty() && $options->isEmpty()) {
            return null;
        }

        $url = route('catalog.category', $category->slug);
        $texts = array_map(FilterChips::valueText(...), $selection->filters);

        foreach ($options->filters as $option) {
            $texts[] = implode(', ', array_map(fn (array $value): string => $value['label'], $option->values));
        }

        $text = implode(', ', $texts);
        $single = $selection->singleValue();

        // VR-19: option filters (colour, size) are never indexed: noindex,follow and the canonical of the category (в).
        $indexable = $options->isEmpty()
            && $single !== null
            && $single->type === AttributeType::List
            && $single->indexable
            && $filters->sort->value === 'default'
            && ! $filters->hasBuiltInFilters()
            && $page === 1
            && ($total === null || $total >= max(1, $this->settings->seo_single_value_min_products));

        if ($indexable) {
            return new FilterSeo(
                canonical: $url.'?'.http_build_query($selection->toQuery()),
                robots: null,
                filtersText: $text,
                heading: $category->name.' — '.$text,
                indexable: true,
            );
        }

        return new FilterSeo($url, 'noindex,follow', $text, $category->name, false);
    }
}
