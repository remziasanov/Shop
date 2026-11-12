<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Jobs\RebuildAttributeCache;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only writer of characteristic values of products (FL-01…FL-03, FL-18, FL-20, 20.2) and of the dictionary.
 * Every write runs in a transaction, refreshes the JSON display cache through {@see AttributeSyncService} and bumps
 * the catalog version (26.3).
 *
 * Values of a pair (product, attribute) are replaced as a whole: `list` takes one or many values, `number` and
 * `boolean` exactly one. A list value is an `int` (id of an existing dictionary value) or text (found in the
 * dictionary case- and ё-insensitively, otherwise created with a slug unique within the characteristic).
 *
 * The instance keeps the dictionary values it has read in memory (fast bulk writes); resolve a fresh instance for
 * each unit of work, do not keep it in a long-running process.
 */
final class AttributeService
{
    public const CHUNK = 500;

    private const NAME_MAX_LENGTH = 150;

    private const SLUG_MAX_LENGTH = 180;

    /** @var array<int, array<string, AttributeValue>> attribute id => normalized text => value */
    private array $valueMaps = [];

    public function __construct(
        private readonly AttributeSyncService $sync,
        private readonly AttributeNameResolver $resolver,
        private readonly SlugService $slugs,
    ) {}

    /**
     * The name resolver of this instance; it learns the attributes this service creates (FL-16).
     */
    public function resolver(): AttributeNameResolver
    {
        return $this->resolver;
    }

    // ------------------------------------------------------------------
    // Dictionary: attributes
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  name*, type, unit, is_filterable, index_single_value, sort_order, is_active, slug
     */
    public function createAttribute(array $data): Attribute
    {
        $name = $this->cleanName((string) ($data['name'] ?? ''));
        $type = $this->typeOf($data['type'] ?? AttributeType::List);
        $unit = $this->cleanUnit($data['unit'] ?? null, $type);
        $slugSource = isset($data['slug']) && trim((string) $data['slug']) !== '' ? (string) $data['slug'] : $name;

        $attribute = DB::transaction(function () use ($data, $name, $type, $unit, $slugSource): Attribute {
            return Attribute::query()->create([
                'name' => $name,
                'slug' => $this->uniqueAttributeSlug($slugSource, null),
                'type' => $type,
                'unit' => $unit,
                'is_filterable' => (bool) ($data['is_filterable'] ?? false),
                'index_single_value' => (bool) ($data['index_single_value'] ?? false),
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : ((int) Attribute::query()->max('sort_order')) + 10,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        });

        $this->resolver->remember($attribute);
        $this->bump();

        return $attribute;
    }

    /**
     * Updates the dictionary entry. The type cannot change while products have values (FL-01). A change of name,
     * unit, order or activity rebuilds the JSON of the products that use the attribute (queued in chunks).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AttributeException
     */
    public function updateAttribute(Attribute $attribute, array $data): Attribute
    {
        $type = array_key_exists('type', $data) ? $this->typeOf($data['type']) : $attribute->type;

        if ($type !== $attribute->type) {
            $count = $this->usageCount($attribute);

            if ($count > 0) {
                throw AttributeException::typeLocked($count);
            }
        }

        $fill = ['type' => $type];

        if (array_key_exists('name', $data)) {
            $fill['name'] = $this->cleanName((string) $data['name']);
        }

        $fill['unit'] = $this->cleanUnit(array_key_exists('unit', $data) ? $data['unit'] : $attribute->unit, $type);

        foreach (['is_filterable', 'index_single_value', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $fill[$flag] = (bool) $data[$flag];
            }
        }

        if (array_key_exists('sort_order', $data)) {
            $fill['sort_order'] = (int) $data['sort_order'];
        }

        if (array_key_exists('slug', $data) && trim((string) $data['slug']) !== '' && $this->slugs->normalize((string) $data['slug']) !== $attribute->slug) {
            $fill['slug'] = $this->uniqueAttributeSlug((string) $data['slug'], $attribute->id);
        }

        $attribute->fill($fill);
        $cacheChanged = $attribute->isDirty(['name', 'unit', 'sort_order', 'is_active']);

        DB::transaction(fn () => $attribute->save());

        $this->resolver->remember($attribute);

        if ($cacheChanged) {
            $this->queueRebuild($this->productIdsUsing($attribute));
        }

        $this->bump();

        return $attribute;
    }

    /**
     * Number of products that have a value of the attribute.
     */
    public function usageCount(Attribute $attribute): int
    {
        return (int) ProductAttributeValue::query()->where('attribute_id', $attribute->id)->distinct()->count('product_id');
    }

    /**
     * Deletes the attribute (FL-20). Refused while products use it, unless `$detach` is true: then the values are
     * removed from the products and the JSON of the affected products is rebuilt by queued jobs.
     *
     * @return int number of products that had a value (0 when nothing was attached)
     *
     * @throws AttributeException with the count of products when it is in use and not detached
     */
    public function deleteAttribute(Attribute $attribute, bool $detach = false): int
    {
        $count = $this->usageCount($attribute);

        if ($count > 0 && ! $detach) {
            throw AttributeException::inUse($count);
        }

        $affected = $count > 0 ? $this->productIdsUsing($attribute) : [];

        DB::transaction(function () use ($attribute): void {
            // The value FK is RESTRICT: remove the rows of products before the dictionary values go.
            ProductAttributeValue::query()->where('attribute_id', $attribute->id)->delete();
            AttributeValue::query()->where('attribute_id', $attribute->id)->delete();
            $attribute->delete();
        });

        unset($this->valueMaps[$attribute->id]);
        $this->resolver->forget($attribute->id);
        $this->queueRebuild($affected);
        $this->bump();

        return $count;
    }

    /**
     * Resolve a header or name by the rules of FL-16 and create the attribute when it is missing: the type and the
     * unit come from the sample values (FL-04 rules), a number takes the unit of the header or of the values, a
     * non-number keeps the full header as its name. Returns null when it is missing and `$create` is false.
     *
     * @param  AttributeStats|iterable<string>  $samples  values seen under this header
     * @param  bool  $filterable  import creates characteristics without a filter (FL-16), the migration with one (FL-04)
     */
    public function findOrCreateByHeader(string $header, AttributeStats|iterable $samples = [], bool $filterable = false, bool $create = true): ?Attribute
    {
        $resolved = $this->resolver->resolve($header);

        if ($resolved->attribute !== null) {
            return $resolved->attribute;
        }

        if (! $create) {
            return null;
        }

        $stats = $samples instanceof AttributeStats ? $samples : AttributeStats::from($samples);

        return $this->createFromResolved($resolved, $stats->infer($resolved->unit), $filterable);
    }

    /**
     * Create the attribute that {@see AttributeNameResolver::resolve()} did not find.
     */
    public function createFromResolved(ResolvedAttributeName $resolved, AttributeTypeInference $inference, bool $filterable): Attribute
    {
        $number = $inference->type === AttributeType::Number;
        $unit = $number ? ($resolved->unit ?? $inference->unit) : null;

        return $this->createAttribute([
            // A unit in the header only makes sense for numbers; otherwise the whole header is the name.
            'name' => $number ? $resolved->name : $resolved->header,
            'type' => $inference->type,
            'unit' => $unit,
            'is_filterable' => $filterable && $inference->filterable,
        ]);
    }

    // ------------------------------------------------------------------
    // Dictionary: values
    // ------------------------------------------------------------------

    /**
     * Finds the value in the dictionary of a list attribute (case, spaces, ё insensitive) or creates it. Does not
     * bump the catalog version: used inside product writes, which bump once; see {@see createValue()}.
     *
     * @throws AttributeException
     */
    public function findOrCreateValue(Attribute $attribute, string $text): AttributeValue
    {
        if ($attribute->type !== AttributeType::List) {
            throw AttributeException::notAList();
        }

        $text = AttributeValueParser::collapse($text);

        if ($text === '') {
            throw AttributeException::valueRequired($attribute->name);
        }

        if (mb_strlen($text) > 255) {
            throw AttributeException::valueTooLong($attribute->name, 255);
        }

        $map = &$this->valueMap($attribute->id);
        $key = AttributeNameResolver::normalize($text);

        if (isset($map[$key])) {
            return $map[$key];
        }

        $slugs = [];
        $order = 0;

        foreach ($map as $existing) {
            $slugs[$existing->slug] = true;
            $order = max($order, $existing->sort_order);
        }

        $base = $this->slugs->normalize($text);
        $base = $base === '' ? 'value' : rtrim(mb_substr($base, 0, self::SLUG_MAX_LENGTH), '-');
        $slug = $base;

        for ($n = 2; isset($slugs[$slug]); $n++) {
            $slug = $base.'-'.$n;
        }

        $value = AttributeValue::query()->create([
            'attribute_id' => $attribute->id,
            'value' => $text,
            'slug' => $slug,
            'sort_order' => $order + 10,
        ]);
        $map[$key] = $value;

        return $value;
    }

    /**
     * Adds a value to the dictionary of a list attribute (the existing one when the text is already there).
     *
     * @throws AttributeException
     */
    public function createValue(Attribute $attribute, string $text): AttributeValue
    {
        $value = DB::transaction(fn (): AttributeValue => $this->findOrCreateValue($attribute, $text));
        $this->bump();

        return $value;
    }

    /**
     * Renames a value; the slug (URL of filters) stays. A name that another value already has is refused: merge them.
     *
     * @throws AttributeException
     */
    public function renameValue(AttributeValue $value, string $text): AttributeValue
    {
        $text = AttributeValueParser::collapse($text);

        if ($text === '') {
            throw AttributeException::valueRequired($value->loadMissing('attribute')->attribute->name);
        }

        $key = AttributeNameResolver::normalize($text);
        $clash = $this->valueMap($value->attribute_id)[$key] ?? null;

        if ($clash !== null && $clash->id !== $value->id) {
            throw AttributeException::valueExists($text);
        }

        DB::transaction(function () use ($value, $text): void {
            $value->update(['value' => $text]);
        });

        unset($this->valueMaps[$value->attribute_id]);
        $this->queueRebuild($this->productIdsWithValue($value));
        $this->bump();

        return $value;
    }

    /**
     * Sets the order of values (drag and drop): the given ids first, in that order; others keep their place after them.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderValues(Attribute $attribute, array $orderedIds): void
    {
        DB::transaction(function () use ($attribute, $orderedIds): void {
            $position = 0;

            foreach ($orderedIds as $id) {
                AttributeValue::query()->where('attribute_id', $attribute->id)->where('id', (int) $id)->update(['sort_order' => ++$position * 10]);
            }

            $rest = AttributeValue::query()->where('attribute_id', $attribute->id)->whereNotIn('id', array_map('intval', $orderedIds))->orderBy('sort_order')->orderBy('id')->pluck('id');

            foreach ($rest as $id) {
                AttributeValue::query()->where('id', $id)->update(['sort_order' => ++$position * 10]);
            }
        });

        unset($this->valueMaps[$attribute->id]);
        $this->queueRebuild($this->productIdsUsing($attribute));
        $this->bump();
    }

    /**
     * Deletes a value that no product uses (FL-02); otherwise refuses with the number of products.
     *
     * @throws AttributeException
     */
    public function deleteValue(AttributeValue $value): void
    {
        $count = (int) ProductAttributeValue::query()->where('attribute_value_id', $value->id)->distinct()->count('product_id');

        if ($count > 0) {
            throw AttributeException::valueInUse($value->value, $count);
        }

        $value->delete();
        unset($this->valueMaps[$value->attribute_id]);
        $this->bump();
    }

    /**
     * «Объединить значения» (FL-02): every product with `$from` gets `$to` instead (products that already have `$to`
     * just lose `$from`), then `$from` is deleted.
     *
     * @return int number of products that had `$from`
     *
     * @throws AttributeException
     */
    public function mergeValues(AttributeValue $from, AttributeValue $to): int
    {
        if ($from->id === $to->id) {
            throw AttributeException::mergeSame();
        }

        if ($from->attribute_id !== $to->attribute_id) {
            throw AttributeException::mergeOtherAttribute();
        }

        $affected = $this->productIdsWithValue($from);

        DB::transaction(function () use ($from, $to, $affected): void {
            foreach (array_chunk($affected, self::CHUNK) as $chunk) {
                $already = ProductAttributeValue::query()
                    ->where('attribute_value_id', $to->id)
                    ->whereIn('product_id', $chunk)
                    ->pluck('product_id')
                    ->all();

                ProductAttributeValue::query()->where('attribute_value_id', $from->id)->whereIn('product_id', $already)->delete();
                ProductAttributeValue::query()->where('attribute_value_id', $from->id)->whereIn('product_id', $chunk)->update(['attribute_value_id' => $to->id]);
            }

            $from->delete();
        });

        $this->queueRebuild($affected);

        unset($this->valueMaps[$from->attribute_id]);
        $this->bump();

        return count($affected);
    }

    // ------------------------------------------------------------------
    // Product values
    // ------------------------------------------------------------------

    /**
     * Writes characteristic values of one product. `$items` is a list of `['attribute_id' => int | 'name' => string,
     * 'values' => mixed]`; empty values remove the characteristic from the product. With `$replaceAll` (the product
     * editor) characteristics missing from `$items` are removed too; without it only the listed ones are replaced
     * (import of some columns).
     *
     * @param  list<array{attribute_id?: int, name?: string, values: mixed}>  $items
     * @param  bool  $bumpVersion  false for bulk loops that bump once at the end
     *
     * @throws AttributeException nothing is written when any item is invalid
     */
    public function setProductValues(Product|int $product, array $items, bool $replaceAll = true, bool $bumpVersion = true): void
    {
        $productId = $product instanceof Product ? $product->id : $product;

        try {
            DB::transaction(function () use ($product, $productId, $items, $replaceAll): void {
                /** @var array<int, list<array<string, mixed>>> $rows */
                $rows = [];

                foreach ($items as $item) {
                    $attribute = $this->attributeOf($item);
                    $rows[$attribute->id] = $this->rowsFor($attribute, $item['values'] ?? null);
                }

                $hadRows = ProductAttributeValue::query()->where('product_id', $productId)->exists();

                $delete = ProductAttributeValue::query()->where('product_id', $productId);

                if (! $replaceAll) {
                    $delete->whereIn('attribute_id', array_keys($rows));
                }

                $delete->delete();

                $insert = [];

                foreach ($rows as $attributeId => $attributeRows) {
                    foreach ($attributeRows as $row) {
                        $insert[] = ['product_id' => $productId, 'attribute_id' => $attributeId] + $row;
                    }
                }

                if ($insert !== []) {
                    ProductAttributeValue::query()->insert($insert);
                }

                $this->sync->syncProduct($product instanceof Product ? $product : $productId, $hadRows || $insert !== []);
            });
        } catch (Throwable $e) {
            // Dictionary values created in the failed transaction no longer exist.
            $this->valueMaps = [];

            throw $e;
        }

        if ($bumpVersion) {
            $this->bump();
        }
    }

    /**
     * «Задать характеристику» (FL-18): the same value(s) of one characteristic for many products, in chunks.
     * Empty values remove the characteristic. Unknown product ids are ignored.
     *
     * @param  array<int>  $productIds
     * @return int number of products written
     *
     * @throws AttributeException also when the migration from JSON is not finished and some product only has JSON
     */
    public function setAttributeForProducts(Attribute $attribute, mixed $values, array $productIds): int
    {
        $this->guardJsonOnly($productIds);

        try {
            $rows = DB::transaction(fn (): array => $this->rowsFor($attribute, $values));
        } catch (Throwable $e) {
            $this->valueMaps = [];

            throw $e;
        }

        $done = 0;

        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::CHUNK) as $chunk) {
            $done += DB::transaction(function () use ($attribute, $rows, $chunk): int {
                $ids = Product::withTrashed()->whereIn('id', $chunk)->pluck('id')->map(fn ($id): int => (int) $id)->all();

                ProductAttributeValue::query()->where('attribute_id', $attribute->id)->whereIn('product_id', $ids)->delete();

                $insert = [];

                foreach ($ids as $id) {
                    foreach ($rows as $row) {
                        $insert[] = ['product_id' => $id, 'attribute_id' => $attribute->id] + $row;
                    }
                }

                foreach (array_chunk($insert, self::CHUNK) as $part) {
                    ProductAttributeValue::query()->insert($part);
                }

                $this->sync->syncProducts($ids, true);

                return count($ids);
            });
        }

        $this->bump();

        return $done;
    }

    /**
     * «Снять характеристику» (FL-18): removes the characteristic from the products, in chunks.
     *
     * @param  array<int>  $productIds
     * @return int number of products that had a value
     *
     * @throws AttributeException the migration from JSON is not finished and some product only has JSON
     */
    public function removeAttributeFromProducts(Attribute $attribute, array $productIds): int
    {
        $this->guardJsonOnly($productIds);

        $done = 0;

        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::CHUNK) as $chunk) {
            $done += DB::transaction(function () use ($attribute, $chunk): int {
                $had = ProductAttributeValue::query()->where('attribute_id', $attribute->id)->whereIn('product_id', $chunk)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all();

                if ($had === []) {
                    return 0;
                }

                ProductAttributeValue::query()->where('attribute_id', $attribute->id)->whereIn('product_id', $had)->delete();
                $this->sync->syncProducts($had, true);

                return count($had);
            });
        }

        if ($done > 0) {
            $this->bump();
        }

        return $done;
    }

    /**
     * Copies all values of one product to another (duplication, 20.6) and rebuilds the copy's JSON.
     *
     * @return int number of rows copied
     */
    public function copyProductValues(Product|int $from, Product|int $to): int
    {
        $fromId = $from instanceof Product ? $from->id : $from;
        $toId = $to instanceof Product ? $to->id : $to;

        return DB::transaction(function () use ($fromId, $toId, $to): int {
            $rows = ProductAttributeValue::query()->where('product_id', $fromId)->orderBy('id')->get(['attribute_id', 'attribute_value_id', 'value_number', 'value_bool']);

            if ($rows->isEmpty()) {
                return 0;
            }

            ProductAttributeValue::query()->where('product_id', $toId)->delete();

            ProductAttributeValue::query()->insert($rows->map(fn (ProductAttributeValue $row): array => [
                'product_id' => $toId,
                'attribute_id' => $row->attribute_id,
                'attribute_value_id' => $row->attribute_value_id,
                // Raw database value: keeps the exact decimal.
                'value_number' => $row->getRawOriginal('value_number'),
                'value_bool' => $row->getRawOriginal('value_bool'),
            ])->all());

            $this->sync->syncProduct($to instanceof Product ? $to : $toId, true);
            $this->bump();

            return $rows->count();
        });
    }

    /**
     * Validates and converts values of one characteristic into rows of `product_attribute_values` (without product
     * id). Creates missing dictionary values of a list, so call it inside a transaction.
     *
     * @return list<array{attribute_value_id: int|null, value_number: string|null, value_bool: bool|null}>
     *
     * @throws AttributeException
     */
    public function rowsFor(Attribute $attribute, mixed $values): array
    {
        $list = [];

        foreach (is_array($values) ? $values : [$values] as $value) {
            if ($value === null || (is_string($value) && AttributeValueParser::collapse($value) === '')) {
                continue;
            }

            $list[] = $value;
        }

        if ($list === []) {
            return [];
        }

        if ($attribute->type !== AttributeType::List && count($list) > 1) {
            throw AttributeException::singleValueOnly($attribute->name);
        }

        return match ($attribute->type) {
            AttributeType::Boolean => [$this->booleanRow($attribute, $list[0])],
            AttributeType::Number => [$this->numberRow($attribute, $list[0])],
            AttributeType::List => $this->listRows($attribute, $list),
        };
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $item
     */
    private function attributeOf(array $item): Attribute
    {
        if (isset($item['attribute_id'])) {
            $attribute = Attribute::query()->find((int) $item['attribute_id']);

            if ($attribute === null) {
                throw AttributeException::unknownAttribute('#'.(int) $item['attribute_id']);
            }

            return $attribute;
        }

        $name = (string) ($item['name'] ?? '');
        $attribute = $name !== '' ? $this->resolver->findByName($name) : null;

        if ($attribute === null) {
            throw AttributeException::unknownAttribute($name);
        }

        return $attribute;
    }

    /**
     * @return array{attribute_value_id: null, value_number: null, value_bool: bool}
     */
    private function booleanRow(Attribute $attribute, mixed $value): array
    {
        $bool = AttributeValueParser::parseBoolean($value);

        if ($bool === null) {
            throw AttributeException::notABoolean($attribute->name, is_scalar($value) ? (string) $value : '');
        }

        return ['attribute_value_id' => null, 'value_number' => null, 'value_bool' => $bool];
    }

    /**
     * @return array{attribute_value_id: null, value_number: string, value_bool: null}
     */
    private function numberRow(Attribute $attribute, mixed $value): array
    {
        $text = is_float($value) ? number_format($value, 4, '.', '') : (is_scalar($value) ? (string) $value : '');
        $parsed = AttributeValueParser::parseNumber($text);

        // A suffix is accepted only when it is the unit of the attribute ("500 Вт").
        $unit = $attribute->unit !== null ? AttributeNameResolver::normalize($attribute->unit) : '';
        $suffixOk = $parsed !== null && ($parsed['suffix'] === '' || ($unit !== '' && AttributeNameResolver::normalize($parsed['suffix']) === $unit));

        if ($parsed === null || ! $suffixOk) {
            throw AttributeException::notANumber($attribute->name, $text);
        }

        $decimal = AttributeValueParser::toDecimal($parsed['number']);

        if ($decimal === null) {
            throw AttributeException::numberOutOfRange($attribute->name, $text);
        }

        return ['attribute_value_id' => null, 'value_number' => $decimal, 'value_bool' => null];
    }

    /**
     * @param  list<mixed>  $list
     * @return list<array{attribute_value_id: int, value_number: null, value_bool: null}>
     */
    private function listRows(Attribute $attribute, array $list): array
    {
        $ids = [];

        foreach ($list as $value) {
            if (is_int($value)) {
                $known = collect($this->valueMap($attribute->id))->first(fn (AttributeValue $v): bool => $v->id === $value);

                if ($known === null) {
                    throw AttributeException::valueNotOfAttribute($attribute->name, $value);
                }

                $ids[$known->id] = true;

                continue;
            }

            if (! is_scalar($value)) {
                throw AttributeException::valueRequired($attribute->name);
            }

            $ids[$this->findOrCreateValue($attribute, (string) $value)->id] = true;
        }

        return array_map(
            fn (int $id): array => ['attribute_value_id' => $id, 'value_number' => null, 'value_bool' => null],
            array_keys($ids),
        );
    }

    /**
     * @return array<string, AttributeValue>
     */
    private function &valueMap(int $attributeId): array
    {
        if (! isset($this->valueMaps[$attributeId])) {
            $this->valueMaps[$attributeId] = [];

            foreach (AttributeValue::query()->where('attribute_id', $attributeId)->orderBy('sort_order')->orderBy('id')->get() as $value) {
                $this->valueMaps[$attributeId][AttributeNameResolver::normalize($value->value)] ??= $value;
            }
        }

        return $this->valueMaps[$attributeId];
    }

    /**
     * Bulk writers refuse products whose v1 characteristics live only in JSON until the migration is finished.
     *
     * @param  array<int>  $productIds
     *
     * @throws AttributeException
     */
    private function guardJsonOnly(array $productIds): void
    {
        $jsonOnly = app(AttributeMigrationState::class)->jsonOnlyProductIds($productIds);

        if ($jsonOnly !== []) {
            throw AttributeException::migrationPending(count($jsonOnly));
        }
    }

    /**
     * @return list<int>
     */
    private function productIdsUsing(Attribute $attribute): array
    {
        return ProductAttributeValue::query()->where('attribute_id', $attribute->id)->distinct()->orderBy('product_id')->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function productIdsWithValue(AttributeValue $value): array
    {
        return ProductAttributeValue::query()->where('attribute_value_id', $value->id)->distinct()->orderBy('product_id')->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Queue the JSON rebuild of the products in jobs of at most {@see RebuildAttributeCache::MAX_IDS} ids.
     *
     * @param  list<int>  $productIds
     */
    private function queueRebuild(array $productIds): void
    {
        foreach (array_chunk($productIds, RebuildAttributeCache::MAX_IDS) as $chunk) {
            RebuildAttributeCache::dispatch($chunk);
        }
    }

    private function cleanName(string $name): string
    {
        $name = AttributeValueParser::collapse($name);

        if ($name === '') {
            throw AttributeException::nameRequired();
        }

        return mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    private function cleanUnit(mixed $unit, AttributeType $type): ?string
    {
        $unit = $unit === null ? '' : AttributeValueParser::collapse((string) $unit);

        if ($unit === '') {
            return null;
        }

        if ($type !== AttributeType::Number) {
            throw AttributeException::unitForNumberOnly();
        }

        return mb_substr($unit, 0, 20);
    }

    private function typeOf(mixed $type): AttributeType
    {
        return $type instanceof AttributeType ? $type : (AttributeType::tryFrom((string) $type) ?? AttributeType::List);
    }

    private function uniqueAttributeSlug(string $source, ?int $ignoreId): string
    {
        $slug = $this->slugs->unique('attributes', $source, $ignoreId);

        return rtrim(mb_substr($slug, 0, 190), '-');
    }

    private function bump(): void
    {
        CatalogVersion::bump();
        DB::afterCommit(static fn () => CatalogVersion::bump());
    }
}
