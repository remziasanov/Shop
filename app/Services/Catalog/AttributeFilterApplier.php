<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `apply()` of TZ 20.5 (FL-08, FL-10): one correlated EXISTS per characteristic. Values of one characteristic are
 * OR-ed (IN), characteristics are AND-ed, a range is inclusive, a boolean keeps only «да»; a product without a value of
 * the characteristic never matches. Only integers and bindings reach the SQL.
 */
final class AttributeFilterApplier
{
    /**
     * @param  Builder<Product>  $query
     * @param  list<string>  $except  slugs of characteristics left out (a facet is counted without its own filter, FL-09)
     * @return Builder<Product>
     */
    public function apply(Builder $query, AttributeSelection $selection, array $except = []): Builder
    {
        foreach ($selection->filters as $filter) {
            if (in_array($filter->slug, $except, true)) {
                continue;
            }

            $query->whereExists(function (QueryBuilder $exists) use ($filter): void {
                $exists->selectRaw('1')
                    ->from('product_attribute_values as pav')
                    ->whereColumn('pav.product_id', 'products.id')
                    ->where('pav.attribute_id', $filter->attributeId);

                match ($filter->type) {
                    AttributeType::List => $exists->whereIn('pav.attribute_value_id', $filter->valueIds()),
                    AttributeType::Number => $this->range($exists, $filter),
                    AttributeType::Boolean => $exists->where('pav.value_bool', true),
                };
            });
        }

        return $query;
    }

    private function range(QueryBuilder $exists, AttributeFilter $filter): void
    {
        if ($filter->min !== null) {
            $exists->where('pav.value_number', '>=', $filter->min);
        }

        if ($filter->max !== null) {
            $exists->where('pav.value_number', '<=', $filter->max);
        }
    }
}
