<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Factories\ProductVariantFactory;
use Illuminate\Support\Facades\DB;

/**
 * An option with the given value texts (in that order), e.g. optionWith('Цвет', ['Красный', 'Синий']).
 *
 * @param  list<string>  $values
 */
function optionWith(string $name, array $values, bool $color = false): OptionType
{
    $type = $color ? OptionType::factory()->color()->create(['name' => $name]) : OptionType::factory()->create(['name' => $name]);

    foreach ($values as $i => $text) {
        OptionValue::factory()->forType($type)->create(['value' => $text, 'sort_order' => $i]);
    }

    return $type->load('values');
}

/**
 * A storefront-visible card with variants enabled and the given axes, without variants yet.
 *
 * @param  array<string, mixed>  $attributes
 */
function variantCard(array $attributes = [], OptionType ...$axes): Product
{
    $product = Product::factory()->withVariants()->inCategory(Category::factory()->create())->create(array_merge([
        'base_price' => '1000.00',
        'stock_qty' => null,
        'reserved_qty' => 0,
        'sales_mode' => SalesMode::Both,
    ], $attributes));

    foreach ($axes as $position => $axis) {
        DB::table('product_options')->insert(['product_id' => $product->id, 'option_type_id' => $axis->id, 'sort_order' => $position]);
    }

    return $product;
}

/**
 * Creates a variant of the card with the option values (one per axis) through the factory (observers run).
 *
 * @param  array<string, mixed>  $attributes
 */
function variantOf(Product $product, array $values, array $attributes = []): ProductVariant
{
    /** @var ProductVariantFactory $factory */
    $factory = ProductVariant::factory()->forProduct($product)->withValues(...$values);

    return $factory->create($attributes);
}

/**
 * A request with one line for a variant (or for a product when $variant is null).
 */
function variantOrder(OrderType $type, Product $product, ?ProductVariant $variant, int $qty): Order
{
    $order = Order::factory()->{$type->value}()->create();
    $item = OrderItem::factory()->forProduct($product, $qty);

    if ($variant !== null) {
        $item = OrderItem::factory()->forVariant($variant, $qty);
    }

    $item->create(['order_id' => $order->id]);

    return $order->load('items');
}
