<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
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
 * Filters: `category_id` (the category and all its descendants), `active` (all|active|inactive),
 * `product_ids` (explicit selection).
 *
 * @phpstan-type Filters array{category_id?: int|string|null, active?: string|null, product_ids?: list<int>|null}
 */
final class CatalogExporter
{
    public const CHUNK = 500;

    public const PATH_SEPARATOR = ' / ';

    public const PHOTO_SEPARATOR = '; ';

    public const LIST_SEPARATOR = '; ';

    public const ACTIVE_ALL = 'all';

    public const ACTIVE_ONLY = 'active';

    public const INACTIVE_ONLY = 'inactive';

    private const COLUMNS = [
        'id', 'category_id', 'sku', 'name', 'brand', 'short_description', 'description', 'base_price', 'cost_price',
        'compare_at_price', 'price_on_request', 'stock_qty', 'unit', 'sales_mode', 'is_active', 'is_featured',
        'is_new', 'sort_order', 'meta_title', 'meta_description', 'age_restriction', 'attributes',
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
     * @return list<string>
     */
    public static function productHeaders(array $attributeNames = []): array
    {
        $headers = array_map(fn (ImportField $field): string => $field->label(), ImportField::cases());

        foreach ($attributeNames as $name) {
            $headers[] = self::attributeHeader($name);
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

    /**
     * Write the file and return the number of exported products.
     *
     * @param  Filters  $filters
     */
    public function export(array $filters, string $path): int
    {
        $attributeNames = $this->attributeNames($filters);
        $paths = $this->categoryPaths();
        $headers = self::productHeaders($attributeNames);
        $widths = [1 => 16, 2 => 40, 3 => 36, 12 => 50];

        $book = new XlsxBook($path);
        $rows = 0;

        try {
            $book->sheet(__('export.sheets.products'), $widths);
            $book->header($headers);

            $this->query($filters)
                ->select(self::COLUMNS)
                ->with('images')
                ->chunkById(self::CHUNK, function ($products) use ($book, $paths, $attributeNames, &$rows): void {
                    $cells = $this->attributeCells($products->pluck('id')->map(fn ($id): int => (int) $id)->all());
                    $legacy = $this->legacyCells($products, $cells);

                    foreach ($products as $product) {
                        $book->row($this->productRow($product, $paths, $attributeNames, $cells[$product->id] ?? $legacy[$product->id] ?? []));
                        $rows++;
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
     * @return list<string|int|float|bool|null>
     */
    private function productRow(Product $product, array $paths, array $attributeNames, array $attributes): array
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
        ];

        foreach ($attributeNames as $name) {
            $row[] = $attributes[$name] ?? null;
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
        if ($product->images->isEmpty()) {
            return null;
        }

        return $product->images
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
