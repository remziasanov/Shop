<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\AttributeType;
use App\Enums\CategoryAccessMode;
use App\Enums\FilterSource;
use App\Enums\OptionDisplayType;
use App\Enums\PromoLinkTargetType;
use App\Enums\PromoScope;
use App\Enums\PromoType;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryFilterService;
use App\Services\Catalog\JsonAttributeMigrator;
use App\Services\Pricing\PriceTierService;
use App\Services\Variants\OptionService;
use App\Services\Variants\VariantService;
use Throwable;

/**
 * Demo data of version 2.0 on top of the demo catalog (TZ stage 15): wholesale prices with quantity tiers for the group
 * «Опт», a category hidden from retail, a T-shirt with variants (colour × size), characteristics and category filters,
 * a promo code and a promo link.
 *
 * Idempotent: everything is found by its code, SKU or slug and updated in place. Only fictional data, no personal data.
 * Demo products carry the `DEMO-` SKU prefix, so `shop:demo --fresh` removes them with the rest.
 */
final class DemoWholesaleSeeder
{
    public const WHOLESALE_CATEGORY = 'demo-opt-nabory';

    public const APPAREL_CATEGORY = 'demo-odezhda';

    public const SHIRT_SKU = 'DEMO-TEE';

    public const PROMO_CODE = 'DEMO10';

    public const PROMO_LINK = 'demo-futbolka';

    public const COLORS = ['Красный' => '#d62828', 'Синий' => '#1d4ed8', 'Чёрный' => '#111827'];

    public const SIZES = ['S', 'M', 'L', 'XL'];

    /** Latin codes of the colours in the SKU of a variant. */
    private const COLOR_CODES = ['Красный' => 'RED', 'Синий' => 'BLUE', 'Чёрный' => 'BLK'];

    /** Product categories (keys of {@see DemoCatalogSeeder::CATEGORIES}) that get wholesale tiers. */
    private const TIERED_CATEGORIES = ['kuhnya', 'posuda', 'sad'];

    /** Wholesale kits: [sku suffix, name, base price]. */
    private const KITS = [
        ['OPT-001', 'Набор посуды оптовый (12 комплектов)', '24000'],
        ['OPT-002', 'Набор садовых инструментов оптовый (10 шт.)', '19000'],
    ];

    public function __construct(
        private readonly PriceTierService $tiers,
        private readonly OptionService $options,
        private readonly VariantService $variants,
        private readonly AttributeService $attributes,
        private readonly CategoryFilterService $filters,
        private readonly JsonAttributeMigrator $migrator,
        private readonly AttributeMigrationState $migration,
    ) {}

    /**
     * @param  array<string, int>  $categoryIds  demo category key => id
     * @param  array<string, mixed>  $report
     * @return list<Product> products created or updated here (for photos)
     */
    public function run(array $categoryIds, array &$report): array
    {
        $report += ['wholesale_prices' => 0, 'variants' => 0, 'promo' => 0, 'filters' => 0];
        $products = [];

        $wholesale = Category::query()->firstOrNew(['slug' => self::WHOLESALE_CATEGORY]);
        $wholesale->fill(['name' => 'Оптовые наборы', 'description' => '<p>Демонстрационная категория, скрытая от розницы.</p>', 'is_active' => true, 'sort_order' => 90])->save();

        $apparel = Category::query()->firstOrNew(['slug' => self::APPAREL_CATEGORY]);
        $apparel->fill(['name' => 'Одежда', 'description' => '<p>Демонстрационная категория с модификациями.</p>', 'is_active' => true, 'sort_order' => 80])->save();

        $opt = CustomerGroup::query()->where('code', 'opt')->first();

        foreach (self::KITS as $index => [$suffix, $name, $price]) {
            $kit = Product::withTrashed()->firstOrNew(['sku' => DemoCatalogSeeder::SKU_PREFIX.$suffix]);
            $kit->fill([
                'category_id' => $wholesale->id,
                'name' => $name,
                'brand' => 'Оптовик',
                'short_description' => $name.': только для оптовых покупателей.',
                'description' => '<p>Демонстрационный товар для оптовых покупателей.</p>',
                'base_price' => $price.'.00',
                'stock_qty' => 500,
                'unit' => 'шт',
                'is_active' => true,
                'sort_order' => 200 + $index,
            ]);

            if ($kit->trashed()) {
                $kit->restore();
            }

            $isNew = ! $kit->exists;
            $kit->save();
            $report[$isNew ? 'products_created' : 'products_updated']++;
            $products[] = $kit;
        }

        $this->hideFromRetail($wholesale);

        try {
            $this->seedShirt($apparel, $products, $report);
        } catch (Throwable $e) {
            $report['warnings'][] = self::SHIRT_SKU.': '.$e->getMessage();
        }

        if ($opt !== null) {
            $this->seedWholesalePrices($opt, $categoryIds, $products, $wholesale->id, $report);
        }

        try {
            $this->seedAttributesAndFilters($categoryIds, $apparel, $report);
        } catch (Throwable $e) {
            $report['warnings'][] = 'filters: '.$e->getMessage();
        }

        $this->seedPromo($report);
        CatalogVersion::bump();

        return $products;
    }

    /**
     * «Оптовые наборы» are not shown to «Гость» and «Розница» (CG-05): the category joins the exclusion list of the groups
     * that see everything, a group with an own list of categories is left alone.
     */
    private function hideFromRetail(Category $category): void
    {
        foreach ([CustomerGroup::GUEST, CustomerGroup::RETAIL] as $code) {
            $group = CustomerGroup::query()->where('code', $code)->first();

            if ($group === null || $group->category_access_mode === CategoryAccessMode::Only) {
                continue;
            }

            $ids = $group->category_access_mode === CategoryAccessMode::AllExcept ? $group->categories()->pluck('categories.id')->all() : [];

            if (! in_array($category->id, $ids, true)) {
                $ids[] = $category->id;
            }

            $group->forceFill(['category_access_mode' => CategoryAccessMode::AllExcept])->save();
            $group->categories()->sync($ids);
        }
    }

    /**
     * Group «Опт»: tiers 1+ (-10 %), 10+ (-20 %), 50+ (-30 %) for the cheap categories and for the kits, and a minimum
     * order sum when the group has none.
     *
     * @param  array<string, int>  $categoryIds
     * @param  list<Product>  $products
     * @param  array<string, mixed>  $report
     */
    private function seedWholesalePrices(CustomerGroup $opt, array $categoryIds, array $products, int $wholesaleCategoryId, array &$report): void
    {
        if ($opt->min_order_amount === null) {
            $opt->forceFill(['min_order_amount' => '5000.00'])->save();
        }

        $ids = array_values(array_filter(array_map(fn (string $key): ?int => $categoryIds[$key] ?? null, self::TIERED_CATEGORIES)));

        $targets = Product::query()->whereIn('category_id', $ids)->where('sku', 'like', DemoCatalogSeeder::SKU_PREFIX.'%')
            ->where('price_on_request', false)->where('has_variants', false)->get();

        foreach ($targets as $product) {
            $this->tiers->replace($product, $opt, $this->rows((string) $product->base_price, ['1' => '0.90', '10' => '0.80', '50' => '0.70']));
            $report['wholesale_prices']++;
        }

        foreach ($products as $kit) {
            if ($kit->category_id === $wholesaleCategoryId) {
                $this->tiers->replace($kit, $opt, $this->rows((string) $kit->base_price, ['1' => '0.85', '5' => '0.75']));
                $report['wholesale_prices']++;
            }
        }
    }

    /**
     * @param  array<int|string, string>  $factors  min_qty => share of the base price
     * @return list<array{min_qty: int, price: string}>
     */
    private function rows(string $base, array $factors): array
    {
        $rows = [];

        foreach ($factors as $qty => $factor) {
            $rows[] = ['min_qty' => (int) $qty, 'price' => bcmul($base, $factor, 2)];
        }

        return $rows;
    }

    /**
     * «Футболка «Север»» with the options «Цвет» × «Размер»: 12 variants with their own articles and stock.
     *
     * @param  list<Product>  $products
     * @param  array<string, mixed>  $report
     */
    private function seedShirt(Category $apparel, array &$products, array &$report): void
    {
        $color = $this->options->findOrCreateType('Цвет', true);
        $size = $this->options->findOrCreateType('Размер', true);

        if ($color === null || $size === null) {
            return;
        }

        $color->forceFill(['is_filterable' => true, 'display_type' => OptionDisplayType::Color])->save();
        $size->forceFill(['is_filterable' => true, 'display_type' => OptionDisplayType::Button])->save();

        $colorValues = [];

        foreach (self::COLORS as $name => $hex) {
            $value = $this->options->findOrCreateValue($color, $name, true);

            if ($value instanceof OptionValue) {
                $value->forceFill(['hex' => $hex])->save();
                $colorValues[$name] = $value;
            }
        }

        $sizeValues = [];

        foreach (self::SIZES as $name) {
            $value = $this->options->findOrCreateValue($size, $name, true);

            if ($value instanceof OptionValue) {
                $sizeValues[$name] = $value;
            }
        }

        $card = Product::withTrashed()->firstOrNew(['sku' => self::SHIRT_SKU]);
        $card->fill([
            'category_id' => $apparel->id,
            'name' => 'Футболка «Север»',
            'brand' => 'Nordika',
            'short_description' => 'Хлопковая футболка в трёх цветах и четырёх размерах.',
            'description' => '<p>Демонстрационный товар с модификациями: выберите цвет и размер.</p>',
            'base_price' => '1500.00',
            'unit' => 'шт',
            'is_active' => true,
            'is_featured' => true,
            'sort_order' => 210,
        ]);

        if ($card->trashed()) {
            $card->restore();
        }

        $wasNew = ! $card->exists;
        $card->save();

        if (! $card->has_variants) {
            $this->variants->enable($card, [$color->id, $size->id]);
        }

        $position = 0;

        foreach ($colorValues as $colorName => $colorValue) {
            foreach ($sizeValues as $sizeName => $sizeValue) {
                $position++;
                $sku = self::SHIRT_SKU.'-'.self::COLOR_CODES[$colorName].'-'.$sizeName;

                if (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
                    continue;
                }

                $this->variants->create($card->refresh(), [
                    'values' => [$colorValue->id, $sizeValue->id],
                    'sku' => $sku,
                    'base_price' => $sizeName === 'XL' ? '1700.00' : '1500.00',
                    'stock_qty' => ($position * 3) % 11 === 0 ? 0 : 4 + ($position % 5) * 3,
                    'sort_order' => $position,
                ]);
                $report['variants']++;
            }
        }

        $products[] = $card->refresh();

        $report[$wasNew ? 'products_created' : 'products_updated']++;
    }

    /**
     * The characteristics of the demo products are migrated from JSON to the tables (the same migration as «Перенести
     * характеристики из JSON»), «Материал», «Цвет» and «Мощность» become filters of the demo categories.
     *
     * @param  array<string, int>  $categoryIds
     * @param  array<string, mixed>  $report
     */
    private function seedAttributesAndFilters(array $categoryIds, Category $apparel, array &$report): void
    {
        if (! $this->migration->isMigrated()) {
            $this->migrator->run();
        }

        $material = Attribute::query()->where('name', 'Материал')->first();
        $colour = Attribute::query()->where('name', 'Цвет')->first();

        foreach ([$material, $colour] as $attribute) {
            if ($attribute !== null) {
                $this->attributes->updateAttribute($attribute, ['is_filterable' => true, 'index_single_value' => $attribute->name === 'Материал']);
            }
        }

        $power = Attribute::query()->where('name', 'Мощность')->first()
            ?? $this->attributes->createAttribute(['name' => 'Мощность', 'type' => AttributeType::Number, 'unit' => 'Вт', 'is_filterable' => true]);

        $powered = Product::query()->whereIn('sku', [DemoCatalogSeeder::sku(0), DemoCatalogSeeder::sku(12), DemoCatalogSeeder::sku(37), DemoCatalogSeeder::sku(14)])->get();

        foreach ($powered as $position => $product) {
            $this->attributes->setProductValues($product, [['attribute_id' => $power->id, 'values' => (string) (15 + $position * 40)]], false);
        }

        $sets = [
            'elektronika' => array_filter([$power, $material, $colour]),
            'mebel' => array_filter([$material, $colour]),
            'dom-i-sad' => array_filter([$material, $colour, $power]),
        ];

        foreach ($sets as $key => $attributes) {
            if (! isset($categoryIds[$key]) || $attributes === []) {
                continue;
            }

            $category = Category::query()->find($categoryIds[$key]);

            if ($category !== null) {
                $this->filters->replaceSet($category, array_map(fn (Attribute $attribute): array => ['attribute_id' => $attribute->id], array_values($attributes)));
                $report['filters']++;
            }
        }

        $rows = $material instanceof Attribute ? [['attribute_id' => $material->id]] : [];

        foreach (['Цвет', 'Размер'] as $name) {
            $type = OptionType::query()->where('name', $name)->first();

            if ($type !== null) {
                $rows[] = ['source' => FilterSource::Option->value, 'option_type_id' => $type->id];
            }
        }

        $this->filters->replaceSet($apparel, $rows);
        $report['filters']++;
    }

    /**
     * The code DEMO10 (10 %) and the link /go/demo-futbolka to the T-shirt with the code and UTM labels.
     *
     * @param  array<string, mixed>  $report
     */
    private function seedPromo(array &$report): void
    {
        $code = PromoCode::query()->firstOrNew(['code' => self::PROMO_CODE]);
        $created = ! $code->exists;

        if ($created) {
            $code->fill([
                'name' => 'Демо: скидка 10 %',
                'type' => PromoType::Percent,
                'value' => '10.00',
                'scope' => PromoScope::All,
                'include_subcategories' => true,
                'only_registered' => false,
                'applies_to_discounted' => false,
                'stackable_with_promotions' => false,
                'is_active' => true,
            ])->save();
            $code->groups()->sync(PromoCode::defaultGroupIds());
            $report['promo']++;
        }

        $card = Product::query()->where('sku', self::SHIRT_SKU)->first();

        if ($card === null) {
            return;
        }

        $link = PromoLink::query()->firstOrNew(['slug' => self::PROMO_LINK]);

        if (! $link->exists) {
            $link->fill([
                'name' => 'Демо: футболка по промокоду',
                'target_type' => PromoLinkTargetType::Product,
                'target_id' => $card->id,
                'promo_code_id' => $code->id,
                'utm_source' => 'demo',
                'utm_medium' => 'link',
                'utm_campaign' => 'tee',
                'is_active' => true,
            ])->save();
            $report['promo']++;
        }
    }
}
