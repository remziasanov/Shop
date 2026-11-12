<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductPresenter;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the active variants of a card with their axes for the product page (VR-09, VR-10): two queries (variants, then
 * their values with the option dictionary), the prices and buttons of every variant come from the presenter without
 * further queries. At most `max_variants_per_product` variants are read (NF-02: +2…3 queries, ~15 KB of JSON).
 */
final class VariantPicker
{
    public function __construct(
        private readonly ProductPresenter $presenter,
        private readonly CatalogSettings $settings,
    ) {}

    /**
     * @param  int|null  $requestedId  `?variant=`; an unknown, inactive or foreign id is ignored
     * @return VariantPickerData|null null when the card has no active variant (nothing to choose)
     */
    public function build(Product $product, ?int $requestedId = null): ?VariantPickerData
    {
        $variants = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(max(1, $this->settings->max_variants_per_product))
            ->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $rows = DB::table('product_variant_values as pvv')
            ->join('option_values as ov', 'ov.id', '=', 'pvv.option_value_id')
            ->join('option_types as ot', 'ot.id', '=', 'pvv.option_type_id')
            ->join('product_options as po', function ($join) use ($product): void {
                $join->on('po.option_type_id', '=', 'pvv.option_type_id')->where('po.product_id', '=', $product->id);
            })
            ->whereIn('pvv.variant_id', $variants->modelKeys())
            ->orderBy('po.sort_order')
            ->orderBy('ot.id')
            ->orderBy('ov.sort_order')
            ->orderBy('ov.id')
            ->get([
                'pvv.variant_id', 'pvv.option_type_id', 'pvv.option_value_id',
                'ot.name as type_name', 'ot.display_type', 'ov.value as value_text', 'ov.hex', 'ov.image_path',
            ]);

        // Axes in card order; values in dictionary order; each value of a variant in axis order.
        $axes = [];
        $byVariant = [];

        foreach ($rows as $row) {
            $typeId = (int) $row->option_type_id;
            $valueId = (int) $row->option_value_id;

            $axes[$typeId] ??= ['id' => $typeId, 'name' => (string) $row->type_name, 'display' => (string) $row->display_type, 'values' => []];
            $axes[$typeId]['values'][$valueId] ??= [
                'id' => $valueId,
                'text' => (string) $row->value_text,
                'hex' => $row->hex !== null ? (string) $row->hex : null,
                'image' => $row->image_path !== null && $row->image_path !== '' ? Storage::disk('public')->url((string) $row->image_path) : null,
            ];
            $byVariant[(int) $row->variant_id][$typeId] = $valueId;
        }

        $axisIds = array_keys($axes);
        $records = [];
        $offers = [];

        foreach ($variants as $variant) {
            $state = $this->presenter->variantState($product, $variant);
            $can = ($state['can']['cart'] ? VariantPickerData::CAN_CART : 0)
                | ($state['can']['reserve'] ? VariantPickerData::CAN_RESERVE : 0)
                | ($state['can']['inquire'] ? VariantPickerData::CAN_INQUIRE : 0)
                | ($state['can']['out'] ? VariantPickerData::OUT : 0);
            $own = $byVariant[$variant->id] ?? [];

            $records[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'bc' => $variant->barcode,
                'v' => array_map(fn (int $typeId): int => $own[$typeId] ?? 0, $axisIds),
                'p' => $state['price'],
                'o' => $state['old'],
                'd' => $state['discount'],
                's' => $state['stock']['text'] ?? null,
                'st' => $state['stock']['state'] ?? null,
                'c' => $can,
                'm' => $state['max'],
                'bo' => $state['backorder'] ? 1 : 0,
            ];

            $offers[] = [
                'price' => (string) $variant->final_price,
                'available' => ! $state['can']['out'],
                'backorder' => $state['backorder'],
                'sku' => $variant->sku,
            ];
        }

        $requested = $requestedId !== null && $variants->contains('id', $requestedId);
        $selectedId = $requested ? (int) $requestedId : $this->firstAvailable($records);
        $colorTypeId = null;

        foreach ($axes as $axis) {
            if ($axis['display'] === 'color') {
                $colorTypeId = $axis['id'];

                break;
            }
        }

        return new VariantPickerData(
            axes: array_values(array_map(function (array $axis): array {
                $axis['values'] = array_values($axis['values']);

                return $axis;
            }, $axes)),
            variants: $records,
            selectedId: $selectedId,
            requested: $requested,
            offers: $offers,
            colorTypeId: $colorTypeId,
            selected: $variants->firstWhere('id', $selectedId) ?? $variants->first(),
        );
    }

    /**
     * The first variant that is not out of stock (VR-10), otherwise the first one.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function firstAvailable(array $records): int
    {
        foreach ($records as $record) {
            if ((((int) $record['c']) & VariantPickerData::OUT) === 0) {
                return (int) $record['id'];
            }
        }

        return (int) $records[0]['id'];
    }
}
