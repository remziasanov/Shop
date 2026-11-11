<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Data of the typed characteristics editor of the product form (FL-03): loads the rows of a product in the shape of
 * the form and saves them through {@see AttributeService}. Also tells whether the product still has to be edited in
 * the legacy JSON form (the migration of FL-04 is not finished and the product only has JSON).
 */
final class ProductAttributeEditor
{
    /** Dictionary values offered as suggestions of a list field. */
    public const SUGGESTIONS = 300;

    public function __construct(
        private readonly AttributeService $attributes,
        private readonly AttributeMigrationState $state,
    ) {}

    /**
     * The product has to be edited as JSON: not migrated yet, it has v1 characteristics and no rows in the tables.
     * Saving it through the typed editor would replace the JSON with an empty set and lose the data.
     */
    public function isLegacy(?Product $product): bool
    {
        if ($product === null || ! $product->exists || $this->state->isMigrated()) {
            return false;
        }

        return $product->specs() !== []
            && ! DB::table('product_attribute_values')->where('product_id', $product->id)->exists();
    }

    /**
     * Rows of the editor in dictionary order: `['attribute_id' => int, 'list' => list<string>, 'number' => ?string, 'boolean' => bool]`.
     *
     * @return list<array{attribute_id: int, list: list<string>, number: ?string, boolean: bool}>
     */
    public function load(Product $product): array
    {
        $rows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->leftJoin('attribute_values as v', 'v.id', '=', 'pav.attribute_value_id')
            ->where('pav.product_id', $product->id)
            ->orderBy('a.sort_order')->orderBy('a.id')->orderBy('v.sort_order')->orderBy('v.id')
            ->get(['a.id as attribute_id', 'a.type', 'v.value', 'pav.value_number', 'pav.value_bool']);

        $result = [];

        foreach ($rows as $row) {
            $id = (int) $row->attribute_id;
            $result[$id] ??= ['attribute_id' => $id, 'list' => [], 'number' => null, 'boolean' => false];

            match ($row->type) {
                'number' => $result[$id]['number'] = $row->value_number === null ? null : AttributeValueParser::formatNumber((string) $row->value_number),
                'boolean' => $result[$id]['boolean'] = (bool) $row->value_bool,
                default => $row->value !== null ? $result[$id]['list'][] = (string) $row->value : null,
            };
        }

        return array_values($result);
    }

    /**
     * Saves the rows of the editor (replaces all characteristics of the product).
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws AttributeException
     */
    public function save(Product $product, array $rows, bool $bumpVersion = true): void
    {
        $this->attributes->setProductValues($product, $this->items($rows), true, $bumpVersion);
    }

    /**
     * Converts form rows into items of {@see AttributeService::setProductValues()}. Rows without a characteristic
     * are ignored, the same characteristic twice is an error.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{attribute_id: int, values: mixed}>
     *
     * @throws AttributeException
     */
    public function items(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (filled($row['attribute_id'] ?? null)) {
                $ids[] = (int) $row['attribute_id'];
            }
        }

        $attributes = Attribute::query()->whereIn('id', $ids)->get()->keyBy('id');
        $items = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! filled($row['attribute_id'] ?? null)) {
                continue;
            }

            $id = (int) $row['attribute_id'];
            $attribute = $attributes->get($id) ?? throw AttributeException::unknownAttribute('#'.$id);

            if (isset($seen[$id])) {
                throw AttributeException::duplicateInProduct($attribute->name);
            }

            $seen[$id] = true;

            $items[] = ['attribute_id' => $id, 'values' => match ($attribute->type) {
                AttributeType::List => array_values(array_filter(array_map('strval', (array) ($row['list'] ?? [])), fn (string $v): bool => trim($v) !== '')),
                AttributeType::Number => $row['number'] ?? null,
                AttributeType::Boolean => (bool) ($row['boolean'] ?? false),
            }];
        }

        return $items;
    }

    /**
     * Characteristics to choose from, `id => "Name, unit"`, filtered by a search term, in dictionary order.
     *
     * @return array<int, string>
     */
    public function attributeOptions(?string $search = null, int $limit = 50): array
    {
        $query = Attribute::query()->ordered()->limit($limit);

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)).'%';
            $query->whereRaw("name like ? escape '!'", [$term]);
        }

        return $query->get()->mapWithKeys(fn (Attribute $a): array => [$a->id => $a->signature().($a->is_active ? '' : ' ('.__('admin_attributes.inactive').')')])->all();
    }

    public function attributeLabel(int|string|null $id): ?string
    {
        return filled($id) ? Attribute::query()->find((int) $id)?->signature() : null;
    }

    /**
     * Dictionary values of a list characteristic as suggestions for free-text input.
     *
     * @return list<string>
     */
    public function valueSuggestions(int|string|null $attributeId): array
    {
        if (! filled($attributeId)) {
            return [];
        }

        return AttributeValue::query()->where('attribute_id', (int) $attributeId)
            ->orderBy('sort_order')->orderBy('id')->limit(self::SUGGESTIONS)->pluck('value')->map(fn ($v): string => (string) $v)->all();
    }

    public function typeOf(int|string|null $attributeId): ?AttributeType
    {
        return filled($attributeId) ? Attribute::query()->whereKey((int) $attributeId)->first(['id', 'type'])?->type : null;
    }

    public function unitOf(int|string|null $attributeId): ?string
    {
        return filled($attributeId) ? Attribute::query()->whereKey((int) $attributeId)->value('unit') : null;
    }
}
