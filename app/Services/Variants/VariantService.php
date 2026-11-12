<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\VariantException;
use App\Models\CartItem;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantValue;
use App\Services\Stock\StockService;
use App\Settings\CatalogSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Variants of a product card (VR-02…VR-05, VR-24, VR-25, VR-27): axes, enabling and disabling, CRUD, bulk operations.
 *
 * Every write runs in a transaction that locks the card row first (the lock order of 19.5: card, then its variants) and
 * validates the SKU through {@see SkuGuard}. Stock goes through {@see StockService}; the prices of a variant and the
 * aggregates of the card follow from the model observers. Failures throw {@see VariantException} (or a
 * ValidationException with the key `sku`).
 *
 * Variant data (`create()` / `update()`): `values` (list of option value ids, one per axis), `sku`, `barcode`,
 * `base_price`, `cost_price`, `compare_at_price`, `fixed_price` (rubles string or null = inherit the card),
 * `stock_qty` (int, null = not tracked), `is_active`, `sort_order`. Absent keys are left as they are on update.
 */
final class VariantService
{
    public const MAX_AXES = 3;

    /** Bulk operations work in batches of this many variants (19.7). */
    public const CHUNK_SIZE = 100;

    /** Price columns a variant may override. */
    public const PRICE_FIELDS = ['base_price', 'cost_price', 'compare_at_price', 'fixed_price'];

    public function __construct(
        private readonly SkuGuard $skus,
        private readonly VariantAggregator $aggregator,
        private readonly CatalogSettings $settings,
        private readonly StockService $stock,
    ) {}

    /**
     * Limit of variants per card (VR-02).
     */
    public function maxVariants(): int
    {
        return max(1, $this->settings->max_variants_per_product);
    }

    /**
     * Combination hash (VR-04): sha1 of the sorted option value ids joined by commas.
     *
     * @param  list<int>  $valueIds
     */
    public static function comboHash(array $valueIds): string
    {
        $ids = array_map('intval', $valueIds);
        sort($ids);

        return sha1(implode(',', $ids));
    }

    /**
     * Axes of the card in order.
     *
     * @return Collection<int, OptionType>
     */
    public function axes(Product $product): Collection
    {
        return $product->options()->get();
    }

    /**
     * Sets the axes: 1 to 3 option types, in the given order (VR-02). The set cannot change while the card has
     * variants (their values depend on it); only the order can.
     *
     * @param  list<int>  $optionTypeIds
     *
     * @throws VariantException
     */
    public function setAxes(Product $product, array $optionTypeIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $optionTypeIds)));

        if (count($ids) < 1 || count($ids) > self::MAX_AXES) {
            throw VariantException::axesCount();
        }

        if (OptionType::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw VariantException::axesUnknown();
        }

        DB::transaction(function () use ($product, $ids): void {
            $this->lockCard($product);

            $current = DB::table('product_options')->where('product_id', $product->id)->pluck('option_type_id')->map(fn (mixed $id): int => (int) $id)->all();
            $sameSet = count($current) === count($ids) && array_diff($current, $ids) === [];

            if (! $sameSet && ProductVariant::query()->where('product_id', $product->id)->exists()) {
                throw VariantException::axesLocked();
            }

            DB::table('product_options')->where('product_id', $product->id)->whereNotIn('option_type_id', $ids)->delete();

            foreach ($ids as $position => $typeId) {
                if (in_array($typeId, $current, true)) {
                    DB::table('product_options')->where('product_id', $product->id)->where('option_type_id', $typeId)->update(['sort_order' => $position]);
                } else {
                    DB::table('product_options')->insert(['product_id' => $product->id, 'option_type_id' => $typeId, 'sort_order' => $position]);
                }
            }

            $product->unsetRelation('options');
        });
    }

    /**
     * Switches variants on for a card (VR-02, VR-25) and sets its axes. Refused while unfinished requests contain the
     * product. With `$currentDataValueIds` (one option value per axis) the card's current prices and stock move into
     * the first variant («Создать модификацию из текущих данных»).
     *
     * @param  list<int>  $axisOptionTypeIds
     * @param  list<int>|null  $currentDataValueIds
     * @return ProductVariant|null the created first variant
     *
     * @throws VariantException
     */
    public function enable(Product $product, array $axisOptionTypeIds, ?array $currentDataValueIds = null): ?ProductVariant
    {
        return DB::transaction(function () use ($product, $axisOptionTypeIds, $currentDataValueIds): ?ProductVariant {
            $locked = $this->lockCard($product);

            if (! $locked->has_variants) {
                $this->assertNoUnfinishedRequests($locked);
            }

            $this->setAxes($locked, $axisOptionTypeIds);

            if (! $locked->has_variants) {
                $locked->has_variants = true;
                // The card keeps its own prices and stock until the first variant exists, so no observer is needed.
                $locked->saveQuietly();
                $product->has_variants = true;
                $product->syncOriginalAttribute('has_variants');
            }

            return $currentDataValueIds === null ? null : $this->createFromCurrentData($locked, $currentDataValueIds);
        });
    }

    /**
     * The first variant from the card's current data: its prices (as own values) and its stock (VR-25).
     *
     * @param  list<int>  $valueIds  one option value per axis
     */
    public function createFromCurrentData(Product $product, array $valueIds, ?string $sku = null): ProductVariant
    {
        return DB::transaction(function () use ($product, $valueIds, $sku): ProductVariant {
            $card = $this->lockCard($product);
            $values = OptionValue::query()->whereIn('id', $valueIds)->get();
            $skuValue = $sku ?? $this->skus->firstFree($card->sku.$values->map(fn (OptionValue $v): string => '-'.$v->slug)->implode(''));

            return $this->create($card, [
                'values' => $valueIds,
                'sku' => $skuValue,
                'base_price' => $card->base_price,
                'cost_price' => $card->cost_price,
                'compare_at_price' => $card->compare_at_price,
                'fixed_price' => $card->fixed_price,
                'stock_qty' => $card->stock_qty,
            ]);
        });
    }

    /**
     * Switches variants off (VR-25): refused while unfinished requests contain the product. The variants and the axes are
     * deleted for good (the order lines keep their snapshots), the card goes back to its own prices and keeps the stock
     * aggregate as its own stock.
     *
     * @throws VariantException
     */
    public function disable(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = $this->lockCard($product);

            if (! $locked->has_variants) {
                return;
            }

            $this->assertNoUnfinishedRequests($locked);

            $ids = ProductVariant::withTrashed()->where('product_id', $locked->id)->pluck('id')->all();

            foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
                // Cart rows are keyed by the variant: they cannot outlive it.
                CartItem::query()->whereIn('variant_id', $chunk)->delete();
                ProductVariant::withTrashed()->whereIn('id', $chunk)->forceDelete();
            }

            DB::table('product_options')->where('product_id', $locked->id)->delete();

            $this->stock->clearReservedOfCard($locked);

            // The observer prices the card from its own fields again (has_variants is dirty).
            $locked->has_variants = false;
            $locked->save();

            $product->setRawAttributes($locked->getAttributes(), true);
            $product->unsetRelation('options');
            $product->unsetRelation('variants');
        });
    }

    /**
     * VR-25: numbers of the unfinished requests (orders new/confirmed/ready/shipped, reservations new/reserved) with
     * the product.
     *
     * @return list<string>
     */
    public function unfinishedRequestNumbers(Product $product): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->whereNull('orders.deleted_at')
            ->where(function ($query): void {
                $query->where(fn ($q) => $q->where('orders.type', OrderType::Order->value)->whereIn('orders.status', [
                    OrderStatus::New->value, OrderStatus::Confirmed->value, OrderStatus::Ready->value, OrderStatus::Shipped->value,
                ]))->orWhere(fn ($q) => $q->where('orders.type', OrderType::Reservation->value)->whereIn('orders.status', [
                    OrderStatus::New->value, OrderStatus::Reserved->value,
                ]));
            })
            ->distinct()
            ->orderBy('orders.number')
            ->pluck('orders.number')
            ->map(fn (mixed $number): string => (string) $number)
            ->all();
    }

    /**
     * @throws VariantException
     */
    public function assertNoUnfinishedRequests(Product $product): void
    {
        $numbers = $this->unfinishedRequestNumbers($product);

        if ($numbers !== []) {
            throw VariantException::unfinishedRequests($numbers);
        }
    }

    /**
     * Creates a variant of a card with variants.
     *
     * @param  array<string, mixed>  $data  see the class description
     *
     * @throws VariantException
     * @throws ValidationException duplicate or invalid SKU
     */
    public function create(Product $product, array $data): ProductVariant
    {
        return $this->aggregator->batch(fn (): ProductVariant => DB::transaction(function () use ($product, $data): ProductVariant {
            $card = $this->lockCard($product);

            if (! $card->has_variants) {
                throw VariantException::notVariantCard();
            }

            $valueIds = $this->validatedValues($card, (array) ($data['values'] ?? []));
            $total = ProductVariant::query()->where('product_id', $card->id)->count() + 1;

            if ($total > $this->maxVariants()) {
                throw VariantException::limitExceeded($this->maxVariants(), $total);
            }

            $hash = self::comboHash(array_keys($valueIds));
            $this->purgeTrashedCombination($card, $hash);

            if (ProductVariant::query()->where('product_id', $card->id)->where('combo_hash', $hash)->exists()) {
                throw VariantException::comboExists();
            }

            $sku = $this->normalizedSku($data['sku'] ?? null);
            $this->skus->assertAvailable($sku);

            $variant = new ProductVariant(array_merge(
                ['is_active' => true, 'sort_order' => $total],
                $this->scalarFields($data),
            ));
            $variant->product_id = $card->id;
            $variant->sku = $sku;
            $variant->combo_hash = $hash;
            $variant->setRelation('product', $card);
            $variant->save();

            $this->writeValues($variant, $valueIds);

            if (array_key_exists('stock_qty', $data)) {
                $this->stock->setStock($variant, $this->normalizedStock($data['stock_qty']));
            }

            return $variant->unsetRelation('product');
        }));
    }

    /**
     * Updates a variant. A change of `values` re-checks the combination; `stock_qty` goes through StockService.
     *
     * @param  array<string, mixed>  $data  see the class description
     *
     * @throws VariantException
     * @throws ValidationException
     */
    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        return $this->aggregator->batch(fn (): ProductVariant => DB::transaction(function () use ($variant, $data): ProductVariant {
            $card = $this->lockCard($variant->stockProduct());
            /** @var ProductVariant $locked */
            $locked = ProductVariant::withTrashed()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('product', $card);

            if (array_key_exists('values', $data)) {
                $valueIds = $this->validatedValues($card, (array) $data['values']);
                $hash = self::comboHash(array_keys($valueIds));

                if ($hash !== $locked->combo_hash) {
                    $this->purgeTrashedCombination($card, $hash);

                    if (ProductVariant::query()->where('product_id', $card->id)->where('combo_hash', $hash)->where('id', '!=', $locked->id)->exists()) {
                        throw VariantException::comboExists();
                    }

                    $locked->combo_hash = $hash;
                    $this->writeValues($locked, $valueIds);
                }
            }

            if (array_key_exists('sku', $data)) {
                $sku = $this->normalizedSku($data['sku']);

                if ($sku !== $locked->sku) {
                    $this->skus->assertAvailable($sku, $locked);
                    $locked->sku = $sku;
                }
            }

            $locked->fill($this->scalarFields($data));
            $locked->save();

            if (array_key_exists('stock_qty', $data)) {
                $this->stock->setStock($locked, $this->normalizedStock($data['stock_qty']));
            }

            $variant->setRawAttributes(ProductVariant::withTrashed()->whereKey($locked->id)->firstOrFail()->getAttributes(), true);

            return $variant;
        }));
    }

    /**
     * Soft-deletes a variant (VR-03). A variant that holds a reservation keeps its row, so the hold is still released.
     */
    public function delete(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant): void {
            $this->lockCard($variant->stockProduct());
            $variant->delete();
        });
    }

    /**
     * Copies a variant under other option values (the same combination cannot exist twice): SKU «{sku}-copy» made unique
     * through SkuGuard, stock zeroed (null stays null), prices, barcode excluded, activity and order copied.
     *
     * @param  list<int>  $valueIds  one option value per axis, a combination that does not exist yet
     *
     * @throws VariantException
     */
    public function duplicate(ProductVariant $source, array $valueIds): ProductVariant
    {
        return DB::transaction(function () use ($source, $valueIds): ProductVariant {
            $card = $this->lockCard($source->stockProduct());

            return $this->create($card, [
                'values' => $valueIds,
                'sku' => $this->skus->firstFree($source->sku, '-copy'),
                'barcode' => null,
                'base_price' => $source->base_price,
                'cost_price' => $source->cost_price,
                'compare_at_price' => $source->compare_at_price,
                'fixed_price' => $source->fixed_price,
                'stock_qty' => $source->stock_qty === null ? null : 0,
                'is_active' => $source->is_active,
                'sort_order' => $source->sort_order,
            ]);
        });
    }

    /**
     * Bulk «Задать остаток» (VR-24), in batches of 100.
     *
     * @param  list<int>  $variantIds
     * @return int number of variants updated
     */
    public function bulkSetStock(array $variantIds, ?int $qty): int
    {
        $qty = $this->normalizedStock($qty);

        return $this->bulk($variantIds, function (ProductVariant $variant) use ($qty): void {
            $this->stock->setStock($variant, $qty);
        });
    }

    /**
     * Bulk «Задать цену» (VR-24): one price column of the variants (null = inherit the card), in batches of 100.
     *
     * @param  list<int>  $variantIds
     * @param  string  $field  one of {@see PRICE_FIELDS}
     * @return int number of variants updated
     *
     * @throws VariantException
     */
    public function bulkSetPrice(array $variantIds, string $field, ?string $price): int
    {
        if (! in_array($field, self::PRICE_FIELDS, true)) {
            throw VariantException::priceInvalid($field);
        }

        $price = $this->normalizedPrice($price, $field);

        return $this->bulk($variantIds, function (ProductVariant $variant) use ($field, $price): void {
            $variant->{$field} = $price;
            $variant->save();
        });
    }

    /**
     * Bulk «Активировать/скрыть» (VR-24), in batches of 100.
     *
     * @param  list<int>  $variantIds
     * @return int number of variants updated
     */
    public function bulkSetActive(array $variantIds, bool $active): int
    {
        return $this->bulk($variantIds, function (ProductVariant $variant) use ($active): void {
            $variant->is_active = $active;
            $variant->save();
        });
    }

    /**
     * @param  list<int>  $variantIds
     * @param  callable(ProductVariant): void  $apply
     */
    private function bulk(array $variantIds, callable $apply): int
    {
        $count = 0;

        $this->aggregator->batch(function () use ($variantIds, $apply, &$count): void {
            foreach (array_chunk(array_values(array_unique(array_map('intval', $variantIds))), self::CHUNK_SIZE) as $chunk) {
                DB::transaction(function () use ($chunk, $apply, &$count): void {
                    $variants = ProductVariant::query()->whereIn('id', $chunk)->orderBy('id')->get();

                    // Card rows first, ascending (19.5), then the variants one by one.
                    $cards = Product::withTrashed()->whereIn('id', $variants->pluck('product_id')->unique()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                    foreach ($variants as $variant) {
                        $variant->setRelation('product', $cards->get($variant->product_id));
                        $apply($variant);
                        $count++;
                    }
                });
            }
        });

        return $count;
    }

    /**
     * @return Product the locked card
     */
    private function lockCard(Product $product): Product
    {
        return Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Option values of a variant checked against the axes: exactly one per axis (VR-03).
     *
     * @param  array<int, mixed>  $valueIds
     * @return array<int, int> option type id by option value id
     *
     * @throws VariantException
     */
    private function validatedValues(Product $card, array $valueIds): array
    {
        $axisIds = DB::table('product_options')->where('product_id', $card->id)->pluck('option_type_id')->map(fn (mixed $id): int => (int) $id)->all();
        $ids = array_values(array_unique(array_map('intval', $valueIds)));

        if ($axisIds === [] || count($ids) !== count($axisIds)) {
            throw VariantException::valuesInvalid();
        }

        $types = DB::table('option_values')->whereIn('id', $ids)->pluck('option_type_id', 'id')->map(fn (mixed $id): int => (int) $id)->all();

        if (count($types) !== count($ids) || count(array_unique($types)) !== count($axisIds) || array_diff(array_values($types), $axisIds) !== []) {
            throw VariantException::valuesInvalid();
        }

        return $types;
    }

    /**
     * @param  array<int, int>  $types  option type id by option value id
     */
    private function writeValues(ProductVariant $variant, array $types): void
    {
        ProductVariantValue::query()->where('variant_id', $variant->id)->toBase()->delete();

        foreach ($types as $valueId => $typeId) {
            ProductVariantValue::query()->create(['variant_id' => $variant->id, 'option_type_id' => $typeId, 'option_value_id' => $valueId]);
        }

        $variant->unsetRelation('values');
    }

    /**
     * A variant that was soft-deleted on its own still occupies its combination; recreating the combination removes it
     * for good (the order lines keep their snapshots).
     */
    private function purgeTrashedCombination(Product $card, string $hash): void
    {
        $ids = ProductVariant::onlyTrashed()->where('product_id', $card->id)->where('combo_hash', $hash)->pluck('id')->all();

        if ($ids !== []) {
            CartItem::query()->whereIn('variant_id', $ids)->delete();
            ProductVariant::onlyTrashed()->whereIn('id', $ids)->forceDelete();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> fillable scalar fields present in the data, normalized
     */
    private function scalarFields(array $data): array
    {
        $fields = Arr::only($data, ['barcode', 'is_active', 'sort_order', ...self::PRICE_FIELDS]);

        if (array_key_exists('barcode', $fields)) {
            $barcode = trim((string) $fields['barcode']);
            $fields['barcode'] = $barcode === '' ? null : mb_substr($barcode, 0, 32);
        }

        foreach (self::PRICE_FIELDS as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = $this->normalizedPrice($fields[$field] === null ? null : (string) $fields[$field], $field);
            }
        }

        return $fields;
    }

    /**
     * @throws ValidationException an empty SKU
     */
    private function normalizedSku(mixed $sku): string
    {
        $sku = trim((string) $sku);

        if ($sku === '') {
            throw ValidationException::withMessages(['sku' => __('variants.errors.sku_required')]);
        }

        return $sku;
    }

    /**
     * @throws VariantException
     */
    private function normalizedPrice(?string $price, string $field): ?string
    {
        if ($price === null || trim($price) === '') {
            return null;
        }

        try {
            $kopecks = Money::fromRubles($price);
        } catch (InvalidArgumentException) {
            throw VariantException::priceInvalid($field);
        }

        if ($kopecks < 0 || $kopecks > 999_999_999_999) {
            throw VariantException::priceInvalid($field);
        }

        return Money::toRubles($kopecks);
    }

    /**
     * @throws VariantException
     */
    private function normalizedStock(mixed $qty): ?int
    {
        if ($qty === null || $qty === '') {
            return null;
        }

        if (! is_numeric($qty) || (int) $qty < 0 || (int) $qty != $qty) {
            throw VariantException::stockInvalid();
        }

        return (int) $qty;
    }
}
