<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\PriceHistory;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<string>
 */
function variantMigrationFiles(): array
{
    return [
        '2026_11_15_100001_create_option_tables',
        '2026_11_15_100002_create_product_variants_tables',
        '2026_11_15_100003_add_variant_columns_to_products_and_images',
        '2026_11_15_100004_add_variant_columns_to_cart_items',
        '2026_11_15_100005_add_variant_columns_to_order_items_and_price_history',
        '2026_11_15_100006_add_option_type_foreign_key_to_category_filters',
    ];
}

/**
 * @return list<list<string>> columns of every unique index of a table
 */
function uniqueIndexColumns(string $table): array
{
    return collect(Schema::getIndexes($table))->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])->map(fn (array $index): array => $index['columns'])->values()->all();
}

it('has the tables, columns and indexes of 19.3', function (): void {
    expect(Schema::hasTable('option_types'))->toBeTrue()
        ->and(Schema::hasColumns('option_types', ['name', 'slug', 'display_type', 'is_filterable', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('option_values', ['option_type_id', 'value', 'slug', 'hex', 'image_path', 'sort_order']))->toBeTrue()
        ->and(Schema::hasColumns('product_options', ['product_id', 'option_type_id', 'sort_order']))->toBeTrue()
        ->and(Schema::hasColumns('product_variants', ['product_id', 'sku', 'barcode', 'combo_hash', 'base_price', 'cost_price', 'compare_at_price', 'fixed_price', 'final_price', 'display_old_price', 'price_calculated_at', 'stock_qty', 'reserved_qty', 'is_active', 'sort_order', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('product_variant_values', ['variant_id', 'option_type_id', 'option_value_id']))->toBeTrue()
        ->and(Schema::hasColumns('products', ['has_variants', 'final_price_max']))->toBeTrue()
        ->and(Schema::hasColumn('product_images', 'option_value_id'))->toBeTrue()
        ->and(Schema::hasColumns('cart_items', ['variant_id', 'variant_key']))->toBeTrue()
        ->and(Schema::hasColumns('order_items', ['variant_id', 'variant_label', 'barcode']))->toBeTrue()
        ->and(Schema::hasColumn('price_history', 'variant_id'))->toBeTrue();

    expect(uniqueIndexColumns('product_variants'))->toContain(['sku'], ['product_id', 'combo_hash'])
        ->and(uniqueIndexColumns('option_values'))->toContain(['option_type_id', 'slug'])
        ->and(uniqueIndexColumns('option_types'))->toContain(['slug']);
});

it('has the cart unique key (cart, product, variant_key) and no longer (cart, product) (VR-12)', function (): void {
    expect(uniqueIndexColumns('cart_items'))->toContain(['cart_id', 'product_id', 'variant_key'])
        ->not->toContain(['cart_id', 'product_id']);
});

it('keeps one cart row per product and per variant (VR-12)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    $s = variantOf($card, [$size->values[0]]);
    $m = variantOf($card, [$size->values[1]]);
    $cart = Cart::factory()->create();

    $row = fn (?ProductVariant $variant) => CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $card->id, 'variant_id' => $variant?->id]);

    $plain = $row(null);
    $rowS = $row($s);
    $row($m);

    expect($plain->variant_key)->toBe(0)->and($rowS->variant_key)->toBe($s->id)->and($cart->items()->count())->toBe(3);

    expect(fn () => $row($s))->toThrow(QueryException::class);
    expect(fn () => $row(null))->toThrow(QueryException::class);
});

it('keeps variant_key in step when the variant of a row changes', function (): void {
    $size = optionWith('Размер', ['S']);
    $card = variantCard([], $size);
    $variant = variantOf($card, [$size->values[0]]);
    $item = CartItem::factory()->create(['product_id' => $card->id]);

    $item->update(['variant_id' => $variant->id]);
    expect($item->fresh()->variant_key)->toBe($variant->id);

    $item->update(['variant_id' => null]);
    expect($item->fresh()->variant_key)->toBe(0);
});

it('sets the variant of a cart row to NULL when the variant is deleted for good, keeps order snapshots', function (): void {
    $size = optionWith('Размер', ['S']);
    $card = variantCard([], $size);
    $variant = variantOf($card, [$size->values[0]]);
    $item = OrderItem::factory()->forVariant($variant)->create(['variant_label' => 'Размер: S']);
    $history = PriceHistory::factory()->create(['product_id' => $card->id, 'variant_id' => $variant->id]);

    $variant->forceDelete();

    expect($item->fresh()->variant_id)->toBeNull()->and($item->fresh()->variant_label)->toBe('Размер: S')->and($item->fresh()->sku)->toBe($variant->sku)
        ->and($history->fresh()->variant_id)->toBeNull();
});

it('rolls every variant migration back and forward again on SQLite, cart index swap included', function (): void {
    $paths = array_map(fn (string $name): string => database_path('migrations/'.$name.'.php'), variantMigrationFiles());

    // SQLite keeps schema changes inside the test transaction; MySQL is covered by the manual migrate/rollback run.
    foreach (array_reverse($paths) as $path) {
        (require $path)->down();
    }

    expect(Schema::hasTable('product_variants'))->toBeFalse()
        ->and(Schema::hasTable('option_types'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'has_variants'))->toBeFalse()
        ->and(Schema::hasColumn('cart_items', 'variant_key'))->toBeFalse()
        ->and(Schema::hasColumn('order_items', 'variant_id'))->toBeFalse()
        ->and(Schema::hasColumn('price_history', 'variant_id'))->toBeFalse()
        ->and(Schema::hasColumn('product_images', 'option_value_id'))->toBeFalse()
        ->and(uniqueIndexColumns('cart_items'))->toContain(['cart_id', 'product_id']);

    foreach ($paths as $path) {
        (require $path)->up();
    }

    expect(Schema::hasTable('product_variants'))->toBeTrue()
        ->and(uniqueIndexColumns('cart_items'))->toContain(['cart_id', 'product_id', 'variant_key'])->not->toContain(['cart_id', 'product_id'])
        ->and(Schema::hasColumn('products', 'final_price_max'))->toBeTrue();
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL commits the test transaction on MySQL');

it('drops cart rows of variants when rolling back, because the old key cannot hold them', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    $cart = Cart::factory()->create();
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $card->id]);
    CartItem::factory()->forVariant(variantOf($card, [$size->values[0]]))->create(['cart_id' => $cart->id]);
    CartItem::factory()->forVariant(variantOf($card, [$size->values[1]]))->create(['cart_id' => $cart->id]);

    $paths = array_map(fn (string $name): string => database_path('migrations/'.$name.'.php'), variantMigrationFiles());

    foreach (array_reverse($paths) as $path) {
        (require $path)->down();
    }

    expect(DB::table('cart_items')->count())->toBe(1);
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'DDL commits the test transaction on MySQL');
