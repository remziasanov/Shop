<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductPriceTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quantity tier (`min_qty` >= 2) or the exact group price (`min_qty` = 1) of a product or a variant for a customer
 * group (GP-02). `price` is a decimal(12,2) string; `variant_key` = COALESCE(variant_id, 0) is maintained on save.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $variant_key
 * @property int $customer_group_id
 * @property int $min_qty
 * @property string $price
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read CustomerGroup $group
 */
class ProductPriceTier extends Model
{
    /** @use HasFactory<ProductPriceTierFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'variant_id', 'customer_group_id', 'min_qty', 'price'];

    protected static function booted(): void
    {
        static::saving(function (ProductPriceTier $tier): void {
            $tier->variant_key = $tier->variant_id === null ? 0 : (int) $tier->variant_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_qty' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function isExactPrice(): bool
    {
        return $this->min_qty === 1;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
