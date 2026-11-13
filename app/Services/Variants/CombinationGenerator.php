<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Exceptions\VariantException;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Combination generator of the product form (VR-05): the manager picks values per axis, sees «будет создано N, уже есть M»
 * with proposed SKUs, edits the list and creates the missing variants. The limit (VR-02) is checked before anything is
 * created; creation runs in one transaction in chunks of 100 and refreshes the card aggregates once.
 */
final class CombinationGenerator
{
    public const DEFAULT_TEMPLATE = '{sku}-{v1}-{v2}-{v3}';

    public function __construct(
        private readonly VariantService $variants,
        private readonly SkuGuard $skus,
        private readonly VariantAggregator $aggregator,
    ) {}

    /**
     * Preview. `$selected` maps an axis option type id to the chosen option value ids; the placeholders of the SKU
     * template are `{sku}` (the card's article) and `{v1}`, `{v2}`, `{v3}` (slugs of the values on the axes). Dangling
     * dashes of unused placeholders are removed, so «{sku}-{v1}-{v2}» works for one and two axes too.
     *
     * @param  array<int, list<int>>  $selected
     *
     * @throws VariantException
     */
    public function preview(Product $product, array $selected, string $skuTemplate = self::DEFAULT_TEMPLATE): CombinationPlan
    {
        $axes = $this->variants->axes($product);

        if ($axes->isEmpty()) {
            throw VariantException::axesCount();
        }

        $lists = [];

        foreach ($axes as $axis) {
            $ids = array_values(array_unique(array_map('intval', $selected[$axis->id] ?? [])));
            $values = OptionValue::query()->where('option_type_id', $axis->id)->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get();

            if ($values->count() !== count($ids)) {
                throw VariantException::valuesInvalid();
            }

            $lists[] = $values->all();
        }

        $existing = ProductVariant::query()->where('product_id', $product->id)->pluck('combo_hash')->flip();
        $rows = [];

        foreach ($this->cartesian($lists) as $combination) {
            $ids = array_map(fn (OptionValue $value): int => $value->id, $combination);
            $rows[] = new CombinationRow(
                $ids,
                $this->label($axes->all(), $combination),
                $this->sku($product, $skuTemplate, $combination),
                isset($existing[VariantService::comboHash($ids)]),
            );
        }

        return new CombinationPlan($this->flagTakenSkus($rows), $existing->count(), $this->variants->maxVariants());
    }

    /**
     * Creates the missing variants from the (edited) list: `[['values' => [valueId, …], 'sku' => '…'], …]`.
     * Combinations that already exist are skipped. Nothing is created when the limit would be exceeded or a SKU is
     * taken, empty or repeated.
     *
     * @param  list<array{values: list<int>, sku: string}>  $rows
     * @param  array<string, mixed>  $defaults  fields for every new variant (prices, stock_qty, is_active, barcode)
     * @return list<ProductVariant> the created variants
     *
     * @throws VariantException
     * @throws ValidationException
     */
    public function create(Product $product, array $rows, array $defaults = []): array
    {
        return DB::transaction(fn (): array => $this->aggregator->batch(function () use ($product, $rows, $defaults): array {
            $existing = ProductVariant::query()->where('product_id', $product->id)->pluck('combo_hash')->flip()->all();
            $todo = [];

            foreach ($rows as $row) {
                $hash = VariantService::comboHash($row['values']);

                if (isset($existing[$hash])) {
                    continue;
                }

                $existing[$hash] = true;
                $todo[] = $row;
            }

            $total = ProductVariant::query()->where('product_id', $product->id)->count() + count($todo);

            if ($total > $this->variants->maxVariants()) {
                throw VariantException::limitExceeded($this->variants->maxVariants(), $total);
            }

            $skus = array_map(fn (array $row): string => trim((string) $row['sku']), $todo);

            if (in_array('', $skus, true)) {
                throw ValidationException::withMessages(['sku' => __('variants.errors.sku_required')]);
            }

            if (count(array_unique($skus)) !== count($skus)) {
                throw ValidationException::withMessages(['sku' => __('variants.errors.sku_repeated')]);
            }

            $taken = $this->skus->takenAmong($skus);

            if ($taken !== []) {
                throw ValidationException::withMessages(['sku' => __('variants.errors.sku_taken', ['sku' => implode(', ', $taken)])]);
            }

            $created = [];

            foreach (array_chunk($todo, VariantService::CHUNK_SIZE) as $chunk) {
                foreach ($chunk as $row) {
                    $created[] = $this->variants->create($product, array_merge($defaults, ['values' => $row['values'], 'sku' => $row['sku']]));
                }
            }

            return $created;
        }));
    }

    /**
     * @param  list<list<OptionValue>>  $lists
     * @return list<list<OptionValue>>
     */
    private function cartesian(array $lists): array
    {
        $result = [[]];

        foreach ($lists as $values) {
            $next = [];

            foreach ($result as $prefix) {
                foreach ($values as $value) {
                    $next[] = [...$prefix, $value];
                }
            }

            $result = $next;
        }

        return $result === [[]] ? [] : $result;
    }

    /**
     * @param  list<OptionType>  $axes
     * @param  list<OptionValue>  $combination
     */
    private function label(array $axes, array $combination): string
    {
        $parts = [];

        foreach ($combination as $i => $value) {
            $parts[] = __('variants.label.pair', ['option' => $axes[$i]->name, 'value' => $value->value]);
        }

        return implode(__('variants.label.separator'), $parts);
    }

    /**
     * @param  list<OptionValue>  $combination
     */
    private function sku(Product $product, string $template, array $combination): string
    {
        $replace = ['{sku}' => $product->sku];

        foreach ([1, 2, 3] as $n) {
            $replace['{v'.$n.'}'] = isset($combination[$n - 1]) ? Str::slug($combination[$n - 1]->slug) : '';
        }

        $sku = strtr($template, $replace);
        // Placeholders of absent axes leave dangling dashes behind.
        $sku = trim((string) preg_replace('/-{2,}/', '-', $sku), '-');

        return Str::limit($sku, SkuGuard::MAX_LENGTH, '');
    }

    /**
     * @param  list<CombinationRow>  $rows
     * @return list<CombinationRow>
     */
    private function flagTakenSkus(array $rows): array
    {
        $candidates = array_values(array_map(fn (CombinationRow $row): string => $row->sku, array_filter($rows, fn (CombinationRow $row): bool => ! $row->exists)));
        $taken = array_flip($this->skus->takenAmong($candidates));
        $counts = array_count_values($candidates);

        return array_map(
            fn (CombinationRow $row): CombinationRow => $row->exists ? $row : new CombinationRow(
                $row->valueIds,
                $row->label,
                $row->sku,
                false,
                isset($taken[$row->sku]) || ($counts[$row->sku] ?? 0) > 1 || $row->sku === '',
            ),
            $rows,
        );
    }
}
