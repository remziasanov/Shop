<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\OptionType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalog\AttributeNameResolver;
use App\Services\Catalog\AttributeValueParser;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Services\Media\ImageProcessor;
use App\Settings\CatalogSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Catalog export to .xlsx in exactly the import template format (EX-01): the same canonical headers,
 * «Х: <signature>» columns for characteristics and an optional «Категории» sheet, so the file can be edited
 * in Excel and uploaded back. The signature is the name of the characteristic with its unit, «Х: Мощность, Вт»
 * (FL-16): the import finds the characteristic by it, so export -> import creates no duplicates. List values are
 * joined with «; », numbers have no unit suffix (it is in the header), booleans are «да» / «нет». Products that
 * only have v1 JSON characteristics (migration of FL-04 not finished) are exported from the JSON. Products are streamed in chunks; memory use does not depend on their count.
 *
 * Variants (VR-18): the row of a card with variants is followed by the rows of its variants (not deleted ones, in their
 * order, inactive ones too): «Артикул карточки» holds the card's article, «Артикул» the variant's, one «Опция: <название>»
 * column per option used as an axis by the exported cards is filled with the variant's value; prices and stock are the
 * variant's own (an empty price = the card's), the photos of the variant's colour are listed on the first variant row of
 * that colour, the card row lists the common photos. The file imports back without changes.
 *
 * Filters: `category_id` (the category and all its descendants), `active` (all|active|inactive),
 * `product_ids` (explicit selection).
 *
 * @phpstan-type Filters array{category_id?: int|string|null, active?: string|null, product_ids?: list<int>|null}
 */
final class CatalogExporter
{
    public const CHUNK = 500;

    /** Cards whose variants are loaded together while exporting. */
    private const VARIANT_CARDS = 50;

    public const PATH_SEPARATOR = ' / ';

    public const PHOTO_SEPARATOR = '; ';

    public const LIST_SEPARATOR = '; ';

    public const ACTIVE_ALL = 'all';

    public const ACTIVE_ONLY = 'active';

    public const INACTIVE_ONLY = 'inactive';

    private const COLUMNS = [
        'id', 'category_id', 'sku', 'name', 'brand', 'short_description', 'description', 'base_price', 'cost_price',
        'compare_at_price', 'price_on_request', 'stock_qty', 'unit', 'sales_mode', 'is_active', 'is_featured',
        'is_new', 'sort_order', 'meta_title', 'meta_description', 'age_restriction', 'attributes', 'has_variants',
    ];

    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * @param  Filters  $filters
     * @return Builder<Product>
     */
    public function query(array $filters): Builder
    {
        $query = Product::query();

        $categoryId = $filters['category_id'] ?? null;

        if ($categoryId !== null && $categoryId !== '') {
            $query->whereIn('products.category_id', $this->tree->descendantIds((int) $categoryId));
        }

        match ($filters['active'] ?? self::ACTIVE_ALL) {
            self::ACTIVE_ONLY => $query->where('products.is_active', true),
            self::INACTIVE_ONLY => $query->where('products.is_active', false),
            default => null,
        };

        $ids = $filters['product_ids'] ?? null;

        if ($ids !== null) {
            $query->whereIn('products.id', array_map('intval', $ids));
        }

        return $query;
    }

    /**
     * @param  Filters  $filters
     */
    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    /**
     * Canonical header row of the «Товары» sheet.
     *
     * @param  list<string>  $attributeNames
     * @param  list<string>  $optionNames  names of the options of variants (VR-18)
     * @return list<string>
     */
    public static function productHeaders(array $attributeNames = [], array $optionNames = []): array
    {
        $headers = array_map(fn (ImportField $field): string => $field->label(), ImportField::cases());

        foreach ($attributeNames as $name) {
            $headers[] = self::attributeHeader($name);
        }

        foreach ($optionNames as $name) {
            $headers[] = self::optionHeader($name);
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    public static function categoryHeaders(): array
    {
        return array_map(fn (CategorySheetField $field): string => $field->label(), CategorySheetField::cases());
    }

    public static function attributeHeader(string $name): string
    {
        return __('export.attribute_prefix').$name;
    }

    public static function optionHeader(string $name): string
    {
        return __('export.option_prefix').$name;
    }

    /**
     * Write the file and return the number of exported products.
     *
     * @param  Filters  $filters
     */
    public function export(array $filters, string $path): int
    {
        $attributeNames = $this->attributeNames($filters);
        $options = $this->optionTypes($filters);
        $paths = $this->categoryPaths();
        $headers = self::productHeaders($attributeNames, array_map(fn (OptionType $type): string => $type->name, $options));
        $widths = [1 => 16, 2 => 40, 3 => 36, 12 => 50];

        $book = new XlsxBook($path);
        $rows = 0;

        try {
            $book->sheet(__('export.sheets.products'), $widths);
            $book->header($headers);

            $this->query($filters)
                ->select(self::COLUMNS)
                ->with('images')
                ->chunkById(self::CHUNK, function ($products) use ($book, $paths, $attributeNames, $options, &$rows): void {
                    $cells = $this->attributeCells($products->pluck('id')->map(fn ($id): int => (int) $id)->all());
                    $legacy = $this->legacyCells($products, $cells);
                    /** @var list<Product> $variantCards */
                    $variantCards = $products->filter(fn (Product $product): bool => $product->has_variants)->values()->all();
                    $cardOffsets = array_flip(array_map(fn (Product $card): int => $card->id, $variantCards));
                    $variantRows = [];

                    foreach ($products as $product) {
                        $book->row($this->productRow($product, $paths, $attributeNames, $cells[$product->id] ?? $legacy[$product->id] ?? [], count($options)));
                        $rows++;

                        if (! $product->has_variants) {
                            continue;
                        }

                        // VR-18: the variants of a card follow its row; they are loaded for a group of cards at a time.
                        if (! isset($variantRows[$product->id])) {
                            $variantRows = $this->variantRows(array_slice($variantCards, $cardOffsets[$product->id], self::VARIANT_CARDS), $options, count($attributeNames));
                        }

                        foreach ($variantRows[$product->id] ?? [] as $variantRow) {
                            $book->row($variantRow);
                        }

                        unset($variantRows[$product->id]);
                    }
                }, 'products.id', 'id');

            $this->writeCategories($book, $paths);
        } finally {
            $book->close();
        }

        return $rows;
    }

    /**
     * Characteristic columns of the exported products: the signatures of the dictionary characteristics they have
     * («Мощность, Вт») and, until the migration from JSON is finished, the JSON names of products without rows
     * (merged into the signature of the dictionary characteristic with that name). Sorted.
     *
     * @param  Filters  $filters
     * @return list<string>
     */
    public function attributeNames(array $filters): array
    {
        $names = [];

        $ids = DB::table('product_attribute_values')
            ->whereIn('product_id', $this->query($filters)->select('products.id'))
            ->distinct()
            ->pluck('attribute_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Attribute::query()->whereIn('id', $chunk)->get() as $attribute) {
                $names[$attribute->signature()] = true;
            }
        }

        if (app(CatalogSettings::class)->attributes_migrated_at === null) {
            $resolver = app(AttributeNameResolver::class);

            $this->query($filters)
                ->select(['products.id', 'products.attributes'])
                ->whereNotNull('products.attributes')
                ->chunkById(1000, function ($products) use (&$names, $resolver): void {
                    $withRows = $this->productsWithRows($products->pluck('id')->map(fn ($id): int => (int) $id)->all());

                    foreach ($products as $product) {
                        if (isset($withRows[$product->id])) {
                            continue;
                        }

                        foreach ($product->specs() as $spec) {
                            $name = trim($spec['name']);

                            if ($name !== '') {
                                $names[$resolver->findByName($name)?->signature() ?? $name] = true;
                            }
                        }
                    }
                }, 'products.id', 'id');
        }

        $names = array_map('strval', array_keys($names));
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Cells of the characteristic columns of the products: product id => signature => value.
     *
     * @param  list<int>  $productIds
     * @return array<int, array<string, string|int|float>>
     */
    private function attributeCells(array $productIds): array
    {
        $cells = [];

        $rows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->leftJoin('attribute_values as v', 'v.id', '=', 'pav.attribute_value_id')
            ->whereIn('pav.product_id', $productIds)
            ->orderBy('pav.product_id')->orderBy('a.id')->orderBy('v.sort_order')->orderBy('v.id')
            ->get(['pav.product_id', 'a.name', 'a.unit', 'a.type', 'v.value', 'pav.value_number', 'pav.value_bool']);

        /** @var array<int, array<string, list<string>>> $lists */
        $lists = [];

        foreach ($rows as $row) {
            $unit = $row->unit !== null ? trim((string) $row->unit) : '';
            $signature = $unit !== '' ? $row->name.', '.$unit : (string) $row->name;
            $productId = (int) $row->product_id;

            match ($row->type) {
                'number' => $row->value_number === null ? null : $cells[$productId][$signature] = self::numberCell((string) $row->value_number),
                'boolean' => $row->value_bool === null ? null : $cells[$productId][$signature] = (bool) $row->value_bool ? __('attributes.boolean_yes') : __('attributes.boolean_no'),
                default => $row->value === null ? null : $lists[$productId][$signature][] = (string) $row->value,
            };
        }

        foreach ($lists as $productId => $bySignature) {
            foreach ($bySignature as $signature => $values) {
                $cells[$productId][$signature] = implode(self::LIST_SEPARATOR, $values);
            }
        }

        return $cells;
    }

    /**
     * Ids of the given products that have rows in the characteristic tables.
     *
     * @param  list<int>  $productIds
     * @return array<int, true>
     */
    private function productsWithRows(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return array_fill_keys(
            DB::table('product_attribute_values')->whereIn('product_id', $productIds)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all(),
            true,
        );
    }

    /**
     * A decimal as a number cell without trailing zeros; text when the float would not represent it exactly.
     */
    private static function numberCell(string $decimal): string|int|float
    {
        $text = AttributeValueParser::formatNumber($decimal);

        if (preg_match('/^-?\d{1,15}$/', $text) === 1) {
            return (int) $text;
        }

        $float = (float) $text;

        return (string) $float === $text ? $float : $text;
    }

    /**
     * Full category paths «A / B / C» by category id, from the cached tree (no query per product).
     *
     * @return array<int, string>
     */
    public function categoryPaths(): array
    {
        $nodes = $this->tree->nodes();
        $paths = [];

        foreach ($nodes as $id => $node) {
            $parts = [$node->name];
            $guard = 0;

            while ($node->parentId !== null && isset($nodes[$node->parentId]) && $guard++ < 10) {
                $node = $nodes[$node->parentId];
                array_unshift($parts, $node->name);
            }

            $paths[$id] = implode(self::PATH_SEPARATOR, $parts);
        }

        return $paths;
    }

    /**
     * @param  array<int, string>  $paths
     * @param  list<string>  $attributeNames
     * @param  array<string, string|int|float>  $attributes  cells of the characteristic columns of the product
     * @param  int  $optionColumns  number of option columns (empty on a card row)
     * @return list<string|int|float|bool|null>
     */
    private function productRow(Product $product, array $paths, array $attributeNames, array $attributes, int $optionColumns = 0): array
    {
        $row = [
            $product->sku,
            $product->name,
            $paths[$product->category_id] ?? '',
            self::number($product->base_price),
            self::number($product->cost_price),
            self::number($product->compare_at_price),
            $product->stock_qty,
            $product->unit,
            $product->brand,
            $product->short_description,
            $product->description,
            $this->photos($product),
            self::yesNo($product->is_active),
            self::yesNo($product->is_featured),
            self::yesNo($product->is_new),
            $product->sales_mode?->label(),
            self::yesNo($product->price_on_request),
            $product->sort_order,
            $product->meta_title,
            $product->meta_description,
            $product->age_restriction?->value,
            // «Артикул карточки» and «Штрихкод» belong to variant rows.
            null,
            null,
        ];

        foreach ($attributeNames as $name) {
            $row[] = $attributes[$name] ?? null;
        }

        for ($i = 0; $i < $optionColumns; $i++) {
            $row[] = null;
        }

        return $row;
    }

    /**
     * Option types used as axes by the exported cards, in dictionary order: one column each (VR-18).
     *
     * @param  Filters  $filters
     * @return list<OptionType>
     */
    public function optionTypes(array $filters): array
    {
        $ids = DB::table('product_options')
            ->whereIn('product_id', $this->query($filters)->where('products.has_variants', true)->select('products.id'))
            ->distinct()
            ->pluck('option_type_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        return OptionType::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get()->all();
    }

    /**
     * The rows of the variants of the given cards (not deleted ones, in their order), by card id.
     *
     * @param  list<Product>  $cards  with `images` loaded
     * @param  list<OptionType>  $options
     * @return array<int, list<list<string|int|float|bool|null>>>
     */
    private function variantRows(array $cards, array $options, int $attributeColumns): array
    {
        $cardIds = array_map(fn (Product $card): int => $card->id, $cards);
        $result = array_fill_keys($cardIds, []);

        $variants = ProductVariant::query()
            ->whereIn('product_id', $cardIds)
            ->orderBy('product_id')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $variantIds = $variants->flatten(1)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($variantIds === []) {
            return $result;
        }

        /** @var array<int, array<int, string>> $texts variant id => option type id => value text */
        $texts = [];
        /** @var array<int, array<int, int>> $valueIds variant id => option type id => option value id */
        $valueIds = [];

        foreach (DB::table('product_variant_values as pvv')
            ->join('option_values as ov', 'ov.id', '=', 'pvv.option_value_id')
            ->whereIn('pvv.variant_id', $variantIds)
            ->get(['pvv.variant_id', 'pvv.option_type_id', 'pvv.option_value_id', 'ov.value']) as $row) {
            $texts[(int) $row->variant_id][(int) $row->option_type_id] = (string) $row->value;
            $valueIds[(int) $row->variant_id][(int) $row->option_type_id] = (int) $row->option_value_id;
        }

        // The colour option of each card: the first axis with display type color.
        $colorTypes = [];

        foreach (DB::table('product_options as po')
            ->join('option_types as ot', 'ot.id', '=', 'po.option_type_id')
            ->whereIn('po.product_id', $cardIds)
            ->where('ot.display_type', 'color')
            ->orderBy('po.product_id')->orderBy('po.sort_order')
            ->get(['po.product_id', 'po.option_type_id']) as $row) {
            $colorTypes[(int) $row->product_id] ??= (int) $row->option_type_id;
        }

        foreach ($cards as $card) {
            $images = $card->images->filter(fn (ProductImage $image): bool => $image->option_value_id !== null)->groupBy('option_value_id');
            $written = [];

            foreach ($variants->get($card->id, collect()) as $variant) {
                $colorValue = isset($colorTypes[$card->id]) ? ($valueIds[$variant->id][$colorTypes[$card->id]] ?? null) : null;
                $photos = null;

                if ($colorValue !== null && ! isset($written[$colorValue]) && $images->has($colorValue)) {
                    $written[$colorValue] = true;
                    $photos = $images->get($colorValue)->map(fn (ProductImage $image): string => $image->urlLarge())->implode(self::PHOTO_SEPARATOR);
                }

                $result[$card->id][] = $this->variantRow($card, $variant, $photos, $options, $texts[$variant->id] ?? [], $attributeColumns);
            }
        }

        return $result;
    }

    /**
     * @param  list<OptionType>  $options
     * @param  array<int, string>  $texts  option type id => value text
     * @return list<string|int|float|bool|null>
     */
    private function variantRow(Product $card, ProductVariant $variant, ?string $photos, array $options, array $texts, int $attributeColumns): array
    {
        $row = [
            $variant->sku,
            null,
            null,
            self::number($variant->base_price),
            self::number($variant->cost_price),
            self::number($variant->compare_at_price),
            $variant->stock_qty,
            null,
            null,
            null,
            null,
            $photos,
            self::yesNo($variant->is_active),
            null,
            null,
            null,
            null,
            $variant->sort_order,
            null,
            null,
            null,
            $card->sku,
            $variant->barcode,
        ];

        for ($i = 0; $i < $attributeColumns; $i++) {
            $row[] = null;
        }

        foreach ($options as $type) {
            $row[] = $texts[$type->id] ?? null;
        }

        return $row;
    }

    /**
     * Cells of products that have no rows in the tables but v1 JSON characteristics (migration not finished): the
     * JSON name is looked up in the dictionary so that it lands in the column of that characteristic.
     *
     * @param  iterable<Product>  $products
     * @param  array<int, array<string, string|int|float>>  $cells
     * @return array<int, array<string, string>>
     */
    private function legacyCells(iterable $products, array $cells): array
    {
        if (app(CatalogSettings::class)->attributes_migrated_at !== null) {
            return [];
        }

        $resolver = app(AttributeNameResolver::class);
        $result = [];

        foreach ($products as $product) {
            if (isset($cells[$product->id])) {
                continue;
            }

            foreach ($product->specs() as $spec) {
                $name = trim($spec['name']);

                if ($name !== '') {
                    $result[$product->id][$resolver->findByName($name)?->signature() ?? $name] ??= $spec['value'];
                }
            }
        }

        return $result;
    }

    private function photos(Product $product): ?string
    {
        // The photos of a colour are listed on the rows of the variants of that colour (VR-18).
        $common = $product->images->filter(fn (ProductImage $image): bool => $image->option_value_id === null);

        if ($common->isEmpty()) {
            return null;
        }

        return $common
            ->map(fn (ProductImage $image): string => $image->urlLarge())
            ->implode(self::PHOTO_SEPARATOR);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function writeCategories(XlsxBook $book, array $paths): void
    {
        $book->sheet(CategorySheetField::SHEET_NAME, [1 => 40, 2 => 40]);
        $book->header(self::categoryHeaders());

        $details = Category::query()
            ->select(['id', 'description', 'meta_title', 'meta_description'])
            ->get()
            ->keyBy('id');

        $disk = Storage::disk(ImageProcessor::DISK);

        foreach ($this->orderedNodes() as $node) {
            $detail = $details->get($node->id);

            $book->row([
                $paths[$node->id] ?? $node->name,
                $detail?->description,
                $node->sortOrder,
                self::yesNo($node->isActive),
                $node->salesModeEnum()?->label(),
                $node->imagePath !== null ? $disk->url($node->imagePath) : null,
                $detail?->meta_title,
                $detail?->meta_description,
                $node->ageRestriction?->value,
            ]);
        }
    }

    /**
     * Categories depth-first in manual order: a parent always precedes its children.
     *
     * @return list<CategoryNode>
     */
    private function orderedNodes(): array
    {
        $result = [];
        $walk = function (array $nodes) use (&$walk, &$result): void {
            foreach ($nodes as $node) {
                $result[] = $node;
                $walk($node->children);
            }
        };
        $walk($this->tree->roots());

        return $result;
    }

    /**
     * Money / numbers as a number cell: "12990.00" => 12990.0 (re-imported as 12990 => 12990.00).
     */
    private static function number(?string $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private static function yesNo(bool $value): string
    {
        return $value ? __('export.yes') : __('export.no');
    }
}
