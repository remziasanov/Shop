<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\Product;
use App\Services\Access\AccessSet;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\StorefrontFilterSets;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\DB;

/**
 * The single-value filter pages of FL-14 «б» for the sitemap (20.6): per category, per list characteristic of its filter
 * set that is indexed by single value, per value that at least `seo_single_value_min_products` visible products of the
 * category and its subcategories carry. Counted for the given access (the sitemap is built for «Гость»). Nothing
 * before the migration from JSON has finished. One grouped query per category and characteristic; no window
 * functions or CTEs.
 */
final class IndexableFilterPages
{
    public function __construct(
        private readonly StorefrontFilterSets $sets,
        private readonly CategoryTree $tree,
        private readonly CatalogSettings $settings,
    ) {}

    /**
     * Paths (with the query string) of the indexable filter pages of the category.
     *
     * @return list<string>
     */
    public function pathsFor(Category $category, AccessSet $access): array
    {
        $indexable = [];

        foreach ($this->sets->for($category->id)->rows as $row) {
            $attribute = $row->attribute;

            if ($attribute !== null && $attribute->type === AttributeType::List && $attribute->index_single_value) {
                $indexable[] = $attribute;
            }
        }

        if ($indexable === []) {
            return [];
        }

        $min = max(1, $this->settings->seo_single_value_min_products);
        $categoryIds = $this->tree->descendantIds($category->id);
        $base = route('catalog.category', $category->slug, false);
        $paths = [];

        foreach ($indexable as $attribute) {
            $query = Product::query()->active()->whereIn('products.category_id', $categoryIds);
            $access->applyToProducts($query);

            $values = $query
                ->join('product_attribute_values as pav', 'pav.product_id', '=', 'products.id')
                ->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
                ->where('pav.attribute_id', $attribute->id)
                ->groupBy('av.id', 'av.slug', 'av.sort_order')
                ->havingRaw('count(distinct pav.product_id) >= ?', [$min])
                ->orderBy('av.sort_order')
                ->orderBy('av.id')
                ->toBase()
                ->get(['av.slug', DB::raw('count(distinct pav.product_id) as product_count')]);

            foreach ($values as $value) {
                $paths[] = $base.'?'.http_build_query(['a' => [$attribute->slug => [(string) $value->slug]]]);
            }
        }

        return $paths;
    }
}
