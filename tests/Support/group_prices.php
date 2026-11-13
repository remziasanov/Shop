<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A product with a base price (no rules, no rounding): final_price equals it.
 *
 * @param  array<string, mixed>  $attributes
 */
function gpProduct(string $price = '1000.00', array $attributes = []): Product
{
    return Product::factory()->create(array_merge(['base_price' => $price], $attributes));
}

/**
 * An exact price (min_qty 1) or a tier of the item for the group, written directly (no validation, no history).
 */
function gpTier(Product|ProductVariant $item, CustomerGroup $group, int $minQty, string $price): ProductPriceTier
{
    return ProductPriceTier::query()->create([
        'product_id' => $item instanceof ProductVariant ? $item->product_id : $item->id,
        'variant_id' => $item instanceof ProductVariant ? $item->id : null,
        'customer_group_id' => $group->id,
        'min_qty' => $minQty,
        'price' => $price,
    ]);
}

/**
 * The stored group price row of the item as ['final' => '900.00', 'old' => null], or null when there is no row.
 *
 * @return array{final: string, old: string|null}|null
 */
function gpRow(Product|ProductVariant|null $item, CustomerGroup $group): ?array
{
    $query = DB::table('product_group_prices_final')->where('customer_group_id', $group->id);

    if ($item instanceof ProductVariant) {
        $query->where('product_id', $item->product_id)->where('variant_key', $item->id);
    } else {
        $query->where('product_id', $item?->id)->where('variant_key', 0);
    }

    $row = $query->first();

    if ($row === null) {
        return null;
    }

    // SQLite returns decimals as numbers: normalize to two decimals like MySQL does.
    return [
        'final' => Money::toRubles(Money::fromRubles((string) $row->final_price)),
        'old' => $row->display_old_price !== null ? Money::toRubles(Money::fromRubles((string) $row->display_old_price)) : null,
    ];
}

/**
 * Columns of every unique non-primary index of a table.
 *
 * @return list<list<string>>
 */
function gpUniqueIndexes(string $table): array
{
    return collect(Schema::getIndexes($table))->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])->map(fn (array $index): array => $index['columns'])->values()->all();
}

/**
 * Makes the following requests of the test come from a signed-in customer of the group.
 */
function gpSignInAs(TestCase $test, CustomerGroup $group): Customer
{
    return signInCustomer($test, makeCustomer(['customer_group_id' => $group->id]));
}

/**
 * Names of the cards of an HTML page in the order they are listed (links `>name</a>`).
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function gpListedNames(string $html, array $names): array
{
    $positions = [];

    foreach ($names as $name) {
        $position = strpos($html, '>'.$name.'</a>');

        if ($position !== false) {
            $positions[$position] = $name;
        }
    }

    ksort($positions);

    return array_values($positions);
}

/**
 * The embedded JSON of the variant picker of a product page.
 *
 * @return array<string, mixed>
 */
function gpVariantData(string $html): array
{
    preg_match('#<script type="application/json" id="variant-data">(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
}
