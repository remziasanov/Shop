<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int|null $variant_id
 * @property int $variant_key COALESCE(variant_id, 0), part of the unique index (VR-12); maintained on save
 * @property int $qty
 * @property string $price_at_add decimal(12,2) string: the unit price of the viewer's group when the item was added or its quantity last changed (GP-11); the reference of the «цена изменилась» signal
 * @property-read Cart $cart
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['cart_id', 'product_id', 'variant_id', 'qty', 'price_at_add'];

    protected static function booted(): void
    {
        // NULL never collides in a unique index, so the index uses this 0-based key instead of variant_id (VR-12).
        static::saving(function (CartItem $item): void {
            $item->variant_key = $item->variant_id === null ? 0 : (int) $item->variant_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'price_at_add' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
