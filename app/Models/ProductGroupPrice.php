<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ProductGroupPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalized price of a customer group (steps 2 and 3 of TZ 21.2) for a product (`variant_key` 0) or a variant; a row
 * exists only when it differs from the retail price (GP-06). Maintained by GroupPriceRecalculator, never by hand.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $variant_key
 * @property int $customer_group_id
 * @property string $final_price
 * @property string|null $display_old_price
 * @property CarbonInterface|null $computed_at
 */
class ProductGroupPrice extends Model
{
    /** @use HasFactory<ProductGroupPriceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'product_group_prices_final';

    protected $fillable = ['product_id', 'variant_id', 'variant_key', 'customer_group_id', 'final_price', 'display_old_price', 'computed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'final_price' => 'decimal:2',
            'display_old_price' => 'decimal:2',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
