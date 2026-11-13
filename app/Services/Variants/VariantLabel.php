<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The human label of a variant: «Цвет: красный, Размер: M», in the axis order of its card (VR-12).
 */
final class VariantLabel
{
    public function for(ProductVariant $variant): string
    {
        return $this->forMany([$variant])[$variant->id] ?? '';
    }

    /**
     * Labels of several variants with two queries.
     *
     * @param  iterable<ProductVariant>  $variants
     * @return array<int, string> by variant id
     */
    public function forMany(iterable $variants): array
    {
        $variants = collect($variants);

        if ($variants->isEmpty()) {
            return [];
        }

        $rows = DB::table('product_variant_values as pvv')
            ->join('option_types as ot', 'ot.id', '=', 'pvv.option_type_id')
            ->join('option_values as ov', 'ov.id', '=', 'pvv.option_value_id')
            ->whereIn('pvv.variant_id', $variants->pluck('id')->all())
            ->get(['pvv.variant_id', 'pvv.option_type_id', 'ot.name as type_name', 'ov.value as value_text']);

        $axes = DB::table('product_options')
            ->whereIn('product_id', $variants->pluck('product_id')->unique()->all())
            ->get(['product_id', 'option_type_id', 'sort_order']);

        $order = [];

        foreach ($axes as $axis) {
            $order[(int) $axis->product_id][(int) $axis->option_type_id] = (int) $axis->sort_order;
        }

        $labels = [];

        foreach ($variants as $variant) {
            /** @var Collection<int, object> $own */
            $own = $rows->where('variant_id', $variant->id)->sortBy(
                fn (object $row): array => [$order[$variant->product_id][(int) $row->option_type_id] ?? PHP_INT_MAX, (int) $row->option_type_id],
            );

            $labels[$variant->id] = $own
                ->map(fn (object $row): string => __('variants.label.pair', ['option' => $row->type_name, 'value' => $row->value_text]))
                ->implode(__('variants.label.separator'));
        }

        return $labels;
    }
}
