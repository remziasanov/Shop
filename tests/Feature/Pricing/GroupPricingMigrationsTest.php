<?php

declare(strict_types=1);

use App\Enums\PriceSource;
use App\Models\CustomerGroup;
use App\Models\OrderItem;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductGroupPrice;
use App\Models\ProductPriceTier;
use App\Services\Pricing\PriceRuleData;
use App\Settings\PricingSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<string>
 */
function groupPricingMigrationFiles(): array
{
    return [
        '2026_11_16_100001_add_pricing_columns_to_customer_groups',
        '2026_11_16_100002_create_product_price_tiers_table',
        '2026_11_16_100003_create_product_group_prices_final_table',
        '2026_11_16_100004_create_price_rule_groups_table',
        '2026_11_16_100005_add_group_price_columns_to_order_items_and_price_history',
    ];
}

it('has the columns, tables and indexes of 21.4', function (): void {
    expect(Schema::hasColumns('customer_groups', ['default_discount_percent', 'show_retail_price']))->toBeTrue()
        ->and(Schema::hasColumns('product_price_tiers', ['product_id', 'variant_id', 'variant_key', 'customer_group_id', 'min_qty', 'price', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('product_group_prices_final', ['product_id', 'variant_id', 'variant_key', 'customer_group_id', 'final_price', 'display_old_price', 'computed_at']))->toBeTrue()
        ->and(Schema::hasColumns('price_rule_groups', ['price_rule_id', 'customer_group_id']))->toBeTrue()
        ->and(Schema::hasColumns('order_items', ['price_source', 'tier_min_qty', 'retail_price']))->toBeTrue()
        ->and(Schema::hasColumns('price_history', ['customer_group_id', 'min_qty']))->toBeTrue();

    expect(gpUniqueIndexes('product_price_tiers'))->toContain(['product_id', 'variant_key', 'customer_group_id', 'min_qty'])
        ->and(gpUniqueIndexes('product_group_prices_final'))->toContain(['product_id', 'variant_key', 'customer_group_id']);

    $indexes = collect(Schema::getIndexes('product_group_prices_final'))->pluck('columns')->all();
    $pk = collect(Schema::getIndexes('price_rule_groups'))->firstWhere('primary', true);

    expect($indexes)->toContain(['customer_group_id', 'final_price'])
        ->and($pk['columns'])->toBe(['price_rule_id', 'customer_group_id']);
});

it('defaults the group discount to 0 and the retail price display to off (GP-01)', function (): void {
    expect(CustomerGroup::guest()->default_discount_percent)->toBe('0.00')
        ->and(CustomerGroup::retail()->show_retail_price)->toBeFalse()
        ->and(CustomerGroup::query()->where('code', 'guest')->value('default_discount_percent'))->toEqual(0);

    $group = CustomerGroup::factory()->discount('12.5')->showRetailPrice()->create();

    expect($group->fresh()->default_discount_percent)->toBe('12.50')->and($group->fresh()->show_retail_price)->toBeTrue();
});

it('maintains variant_key of tiers and keeps one row per item, group and quantity', function (): void {
    $size = optionWith('Размер', ['S']);
    $card = variantCard([], $size);
    $variant = variantOf($card, [$size->values[0]]);
    $group = CustomerGroup::factory()->create();
    $product = gpProduct();

    $plain = gpTier($product, $group, 10, '900.00');
    $own = gpTier($variant, $group, 10, '400.00');

    expect($plain->variant_key)->toBe(0)->and($own->variant_key)->toBe($variant->id);

    expect(fn () => gpTier($product, $group, 10, '800.00'))->toThrow(QueryException::class);
    expect(fn () => gpTier($variant, $group, 10, '300.00'))->toThrow(QueryException::class);

    gpTier($product, $group, 20, '800.00');
    gpTier($product, CustomerGroup::factory()->create(), 10, '800.00');

    expect(ProductPriceTier::query()->count())->toBe(4);
});

it('cascades tiers and group prices with the product, the variant and the group', function (): void {
    $size = optionWith('Размер', ['S']);
    $card = variantCard([], $size);
    $variant = variantOf($card, [$size->values[0]]);
    $group = CustomerGroup::factory()->create();
    $product = gpProduct();
    gpTier($product, $group, 5, '900.00');
    gpTier($variant, $group, 5, '900.00');
    ProductGroupPrice::factory()->create(['product_id' => $product->id, 'customer_group_id' => $group->id]);
    ProductGroupPrice::factory()->create(['product_id' => $card->id, 'variant_id' => $variant->id, 'variant_key' => $variant->id, 'customer_group_id' => $group->id]);

    $variant->forceDelete();

    expect(ProductPriceTier::query()->count())->toBe(1)->and(ProductGroupPrice::query()->count())->toBe(1);

    $product->forceDelete();

    expect(ProductPriceTier::query()->count())->toBe(0)->and(ProductGroupPrice::query()->count())->toBe(0);

    $other = gpProduct();
    gpTier($other, $group, 5, '900.00');
    ProductGroupPrice::factory()->create(['product_id' => $other->id, 'customer_group_id' => $group->id]);
    $rule = PriceRule::factory()->create();
    $rule->groups()->attach($group->id);

    $group->delete();

    expect(ProductPriceTier::query()->count())->toBe(0)
        ->and(ProductGroupPrice::query()->count())->toBe(0)
        ->and(DB::table('price_rule_groups')->count())->toBe(0);
});

it('rejects a second group price row of the same item and group', function (): void {
    $group = CustomerGroup::factory()->create();
    $product = gpProduct();
    ProductGroupPrice::factory()->create(['product_id' => $product->id, 'customer_group_id' => $group->id]);

    expect(fn () => ProductGroupPrice::factory()->create(['product_id' => $product->id, 'customer_group_id' => $group->id]))->toThrow(QueryException::class);
});

it('binds rules to groups and exposes them in the rule snapshot and its cache form (GP-04)', function (): void {
    $a = CustomerGroup::factory()->create();
    $b = CustomerGroup::factory()->create();
    $rule = PriceRule::factory()->create();
    $rule->groups()->attach([$b->id, $a->id]);

    $data = PriceRuleData::fromModel($rule->fresh());

    expect($data->groupIds)->toBe([$a->id, $b->id])
        ->and(PriceRuleData::fromArray($data->toArray())->groupIds)->toBe([$a->id, $b->id])
        ->and($data->appliesToGroup($a->id))->toBeTrue()
        ->and($data->appliesToGroup(999))->toBeFalse()
        ->and($data->appliesToGroup(null))->toBeFalse();

    $legacy = $data->toArray();
    unset($legacy['group_ids']);

    expect(PriceRuleData::fromArray($legacy)->groupIds)->toBe([])
        ->and(PriceRuleData::fromArray($legacy)->appliesToGroup(null))->toBeTrue();
});

it('keeps the price source, tier and retail price of an order line (GP-12)', function (): void {
    $item = OrderItem::factory()->create(['price_source' => PriceSource::Tier, 'tier_min_qty' => 10, 'retail_price' => '1000.00']);

    $fresh = $item->fresh();

    expect($fresh->price_source)->toBe(PriceSource::Tier)->and($fresh->tier_min_qty)->toBe(10)->and($fresh->retail_price)->toBe('1000.00')
        ->and(OrderItem::factory()->create()->fresh()->price_source)->toBeNull();
});

it('keeps group and quantity of a price history row (GP-16)', function (): void {
    $group = CustomerGroup::factory()->create();
    $row = PriceHistory::factory()->create(['field' => 'group_tier', 'customer_group_id' => $group->id, 'min_qty' => 10]);

    expect($row->fresh()->min_qty)->toBe(10)->and($row->group->id)->toBe($group->id);

    $group->delete();

    expect($row->fresh()->customer_group_id)->toBeNull()->and($row->fresh()->min_qty)->toBe(10);
});

it('has the pricing settings of GP-19 with defaults', function (): void {
    $settings = app(PricingSettings::class);

    expect($settings->group_prices_rounding)->toBeTrue()
        ->and($settings->tier_hint_enabled)->toBeTrue()
        ->and($settings->tier_hint_percent)->toBe(50)
        ->and($settings->tier_hint_qty)->toBe(20);
});

it('rolls the group pricing migrations back and forward again on SQLite', function (): void {
    $paths = array_map(fn (string $name): string => database_path('migrations/'.$name.'.php'), groupPricingMigrationFiles());

    // SQLite keeps schema changes inside the test transaction; MySQL is covered by the manual migrate/rollback run.
    foreach (array_reverse($paths) as $path) {
        (require $path)->down();
    }

    expect(Schema::hasTable('product_price_tiers'))->toBeFalse()
        ->and(Schema::hasTable('product_group_prices_final'))->toBeFalse()
        ->and(Schema::hasTable('price_rule_groups'))->toBeFalse()
        ->and(Schema::hasColumn('customer_groups', 'default_discount_percent'))->toBeFalse()
        ->and(Schema::hasColumn('order_items', 'price_source'))->toBeFalse()
        ->and(Schema::hasColumn('price_history', 'customer_group_id'))->toBeFalse()
        ->and(Schema::hasColumn('price_history', 'variant_id'))->toBeTrue();

    foreach ($paths as $path) {
        (require $path)->up();
    }

    expect(Schema::hasTable('product_price_tiers'))->toBeTrue()
        ->and(Schema::hasColumns('customer_groups', ['default_discount_percent', 'show_retail_price']))->toBeTrue()
        ->and(Schema::hasColumns('price_history', ['customer_group_id', 'min_qty']))->toBeTrue()
        ->and(Product::query()->count())->toBe(0);
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL commits the test transaction on MySQL');
