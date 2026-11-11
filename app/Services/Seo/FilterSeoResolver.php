<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\FilterChips;

/**
 * FL-14: which category pages with characteristic filters search engines may index.
 *
 * (а) no characteristic filter: not handled here, the page keeps the behaviour of SF-13/SF-14.
 * (б) exactly one filter with exactly one value of a list characteristic with «индексировать страницы с одним
 *     значением», no sort, no built-in filter, first page: indexable, canonical to its own normalized URL, title and
 *     h1 «{категория} — {значение}».
 * (в) anything else with characteristic filters (combinations, ranges, booleans, several values, sort, built-in
 *     filters, page > 1): `noindex,follow` and canonical to the category without parameters.
 */
final class FilterSeoResolver
{
    /**
     * @return FilterSeo|null null for (а)
     */
    public function resolve(Category $category, CatalogFilters $filters, int $page): ?FilterSeo
    {
        $selection = $filters->attributes;

        if ($selection->isEmpty()) {
            return null;
        }

        $url = route('catalog.category', $category->slug);
        $text = implode(', ', array_map(FilterChips::valueText(...), $selection->filters));
        $single = $selection->singleValue();

        $indexable = $single !== null
            && $single->type === AttributeType::List
            && $single->indexable
            && $filters->sort->value === 'default'
            && ! $filters->hasBuiltInFilters()
            && $page === 1;

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
