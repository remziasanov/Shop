<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * SQL fragments of the viewer's price for catalog queries (GP-08): the denormalized group price of the card row
 * (`variant_key` 0) or the retail `final_price`. A correlated lookup on the unique index
 * (product_id, variant_key, customer_group_id) instead of a join: it adds no columns, so the unqualified column names
 * of the other filters stay unambiguous, and it never duplicates a row. MySQL 5.7 and SQLite compatible.
 */
final class GroupPriceSql
{
    /**
     * `COALESCE(group price, products.final_price)`; the group id is an integer, inlined.
     */
    public static function price(?int $groupId, string $products = 'products'): string
    {
        if ($groupId === null || $groupId <= 0) {
            return $products.'.final_price';
        }

        return 'COALESCE((SELECT gp.final_price FROM product_group_prices_final gp WHERE gp.product_id = '.$products.'.id AND gp.variant_key = 0 AND gp.customer_group_id = '.$groupId.'), '.$products.'.final_price)';
    }

    /**
     * The struck-through price of the viewer (GP-09): the old price of the group row when the group has a row, otherwise
     * the retail one. Null when there is none.
     */
    public static function oldPrice(?int $groupId, string $products = 'products'): string
    {
        if ($groupId === null || $groupId <= 0) {
            return $products.'.display_old_price';
        }

        $row = 'FROM product_group_prices_final gp WHERE gp.product_id = '.$products.'.id AND gp.variant_key = 0 AND gp.customer_group_id = '.$groupId;

        return 'CASE WHEN EXISTS (SELECT 1 '.$row.') THEN (SELECT gp.display_old_price '.$row.') ELSE '.$products.'.display_old_price END';
    }

    /**
     * Condition «the dearest active variant of a card costs at least :price» for the viewer's group (VR-07, GP-08);
     * a variant without own group price counts with its retail price. One `?` binding: the price in rubles.
     */
    public static function variantAtLeast(?int $groupId, string $products = 'products'): string
    {
        $join = $groupId === null || $groupId <= 0
            ? ''
            : ' LEFT JOIN product_group_prices_final gpv ON gpv.product_id = v.product_id AND gpv.variant_key = v.id AND gpv.customer_group_id = '.$groupId;
        $price = $groupId === null || $groupId <= 0 ? 'v.final_price' : 'COALESCE(gpv.final_price, v.final_price)';

        return '('.$products.'.has_variants = 1 AND EXISTS (SELECT 1 FROM product_variants v'.$join.' WHERE v.product_id = '.$products.'.id AND v.is_active = 1 AND v.deleted_at IS NULL AND '.$price.' >= CAST(? AS DECIMAL(12,2))))';
    }
}
