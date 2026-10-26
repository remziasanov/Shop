<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Breadcrumb trails for storefront pages (SF-06). Items are ['name' => string, 'url' => string|null];
 * the last item (the current page) has no url.
 */
final class Breadcrumbs
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * Home > Catalog > ancestors > the category (as the current page unless $extra is given).
     *
     * @param  string|null  $extra  name of a page below the category (e.g. a product); the category then becomes a link
     * @return list<array{name: string, url: string|null}>
     */
    public function forCategory(int $categoryId, ?string $extra = null): array
    {
        $items = [
            ['name' => __('storefront.breadcrumbs.home'), 'url' => route('home')],
            ['name' => __('storefront.breadcrumbs.catalog'), 'url' => route('catalog.index')],
        ];

        foreach ($this->tree->breadcrumbs($categoryId) as $node) {
            $items[] = ['name' => $node->name, 'url' => route('catalog.category', $node->slug)];
        }

        if ($extra === null) {
            $items[array_key_last($items)]['url'] = null;
        } else {
            $items[] = ['name' => $extra, 'url' => null];
        }

        return $items;
    }

    /**
     * Home > Catalog (the current page).
     *
     * @return list<array{name: string, url: string|null}>
     */
    public function forCatalog(): array
    {
        return [
            ['name' => __('storefront.breadcrumbs.home'), 'url' => route('home')],
            ['name' => __('storefront.breadcrumbs.catalog'), 'url' => null],
        ];
    }
}
