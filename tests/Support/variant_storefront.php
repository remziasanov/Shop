<?php

declare(strict_types=1);

use App\Enums\OptionDisplayType;
use App\Models\OptionType;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * A storefront T-shirt card with two axes (colour swatches, size buttons) and three variants:
 *
 *  - red / S   1000 ₽, stock 5, barcode 4600000000011
 *  - red / M   1200 ₽, stock 0
 *  - blue / S  1000 ₽, stock 2
 *
 * @param  array<string, mixed>  $card  attributes of the card
 * @param  string  $prefix  article prefix: card `TS-CARD`, variants `TS-RED-S` …
 * @return array{card: Product, color: OptionType, size: OptionType, redS: ProductVariant, redM: ProductVariant, blueS: ProductVariant}
 */
function vsShirt(array $card = [], string $prefix = 'TS'): array
{
    $color = optionWith('Цвет', ['Красный', 'Синий'], true);
    $color->update(['is_filterable' => true]);
    $color->values[0]->update(['hex' => '#ff0000']);
    $color->values[1]->update(['hex' => '#0000ff']);
    $size = optionWith('Размер', ['S', 'M']);
    $size->update(['is_filterable' => true, 'display_type' => OptionDisplayType::Button]);

    $product = variantCard($card + ['name' => 'Футболка', 'sku' => $prefix.'-CARD'], $color, $size);

    return [
        'card' => $product,
        'color' => $color,
        'size' => $size,
        'redS' => variantOf($product, [$color->values[0], $size->values[0]], ['sku' => $prefix.'-RED-S', 'barcode' => '4600000000011', 'stock_qty' => 5, 'base_price' => '1000.00', 'sort_order' => 1]),
        'redM' => variantOf($product, [$color->values[0], $size->values[1]], ['sku' => $prefix.'-RED-M', 'stock_qty' => 0, 'base_price' => '1200.00', 'sort_order' => 2]),
        'blueS' => variantOf($product, [$color->values[1], $size->values[0]], ['sku' => $prefix.'-BLUE-S', 'stock_qty' => 2, 'base_price' => '1000.00', 'sort_order' => 3]),
    ];
}
