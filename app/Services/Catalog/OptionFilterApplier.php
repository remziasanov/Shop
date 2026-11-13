<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Settings\SalesSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `apply()` for option filters (VR-19): a card matches when ONE of its active variants has, for every selected option,
 * one of the selected values (values of one option are OR-ed, options are AND-ed) and, with «только доступные
 * модификации», is available. One correlated EXISTS over `product_variants` with an EXISTS over
 * `product_variant_values` per option; only integers and bindings reach the SQL.
 */
final class OptionFilterApplier
{
    public function __construct(private readonly SalesSettings $sales) {}

    /**
     * @param  Builder<Product>  $query
     * @param  list<string>  $except  slugs of options left out
     * @return Builder<Product>
     */
    public function apply(Builder $query, OptionSelection $selection, array $except = []): Builder
    {
        if ($selection->isEmpty()) {
            return $query;
        }

        $query->whereExists(function (QueryBuilder $exists) use ($selection, $except): void {
            $exists->selectRaw('1')
                ->from('product_variants as pv')
                ->whereColumn('pv.product_id', 'products.id')
                ->where('pv.is_active', true)
                ->whereNull('pv.deleted_at');

            $this->constrain($exists, $selection, $except);
        });

        return $query;
    }

    /**
     * The conditions on one variant row (`pv`): the values of the selected options and the availability switch.
     *
     * @param  list<string>  $except  slugs of options left out (a facet is counted without its own option, FL-09)
     */
    public function constrain(QueryBuilder $variants, OptionSelection $selection, array $except = [], string $alias = 'pv'): void
    {
        foreach ($selection->filters as $filter) {
            if (in_array($filter->slug, $except, true)) {
                continue;
            }

            $variants->whereExists(function (QueryBuilder $values) use ($filter, $alias): void {
                $values->selectRaw('1')
                    ->from('product_variant_values as pvv')
                    ->whereColumn('pvv.variant_id', $alias.'.id')
                    ->where('pvv.option_type_id', $filter->optionTypeId)
                    ->whereIn('pvv.option_value_id', $filter->valueIds());
            });
        }

        if ($selection->availableOnly && $this->sales->stock_tracking) {
            $variants->where(function (QueryBuilder $stock) use ($alias): void {
                $stock->whereNull($alias.'.stock_qty')->orWhereRaw($alias.'.stock_qty - '.$alias.'.reserved_qty > 0');
            });
        }
    }
}
