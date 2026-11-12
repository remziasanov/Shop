<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Links in the specs table of the product card (TZ 20.6): the values of characteristics that are indexed by single
 * value (FL-14 «б») and belong to the filter set of the product's category lead to the category page filtered by that
 * value. The table itself still comes from the JSON cache of the product; this only adds the links, in two queries
 * (the filter set, the value slugs of the product), and nothing before the migration from JSON has finished.
 */
final class SpecLinker
{
    public function __construct(private readonly StorefrontFilterSets $sets) {}

    /**
     * @param  list<array{name: string, value: string}>  $specs  {@see Product::specs()}
     * @return list<array{name: string, value: string, links: list<array{text: string, url: string}>}>
     */
    public function link(Product $product, Category $category, array $specs): array
    {
        $linked = array_map(fn (array $spec): array => $spec + ['links' => []], $specs);

        if ($specs === []) {
            return $linked;
        }

        /** @var array<int, string> $indexable attribute id => name */
        $indexable = [];

        foreach ($this->sets->for($product->category_id)->rows as $row) {
            $attribute = $row->attribute;

            if ($attribute !== null && $attribute->type === AttributeType::List && $attribute->index_single_value) {
                $indexable[$attribute->id] = $attribute->name;
            }
        }

        if ($indexable === []) {
            return $linked;
        }

        $rows = DB::table('product_attribute_values as pav')
            ->join('attribute_values as v', 'v.id', '=', 'pav.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->where('pav.product_id', $product->id)
            ->whereIn('pav.attribute_id', array_keys($indexable))
            ->orderBy('v.sort_order')
            ->orderBy('v.id')
            ->get(['pav.attribute_id', 'a.slug as attribute_slug', 'v.value', 'v.slug as value_slug']);

        $base = route('catalog.category', $category->slug);
        $links = [];

        foreach ($rows as $row) {
            $links[$indexable[(int) $row->attribute_id]][] = [
                'text' => (string) $row->value,
                'url' => $base.'?'.http_build_query(['a' => [(string) $row->attribute_slug => [(string) $row->value_slug]]]),
            ];
        }

        foreach ($linked as $i => $spec) {
            if (isset($links[$spec['name']])) {
                $linked[$i]['links'] = $links[$spec['name']];
            }
        }

        return $linked;
    }
}
