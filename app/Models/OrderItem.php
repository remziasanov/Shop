<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a product in a request. `stock_applied_qty` is technical (D-046).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string $sku
 * @property string|null $variant_label snapshot «Цвет: красный, Размер: M» (VR-12)
 * @property string|null $barcode snapshot of the variant barcode
 * @property string $name
 * @property string $unit
 * @property string|null $price
 * @property int $qty
 * @property string|null $total
 * @property int $stock_applied_qty
 * @property-read Order $order
 * @property-read Product|null $product
 * @property-read ProductVariant|null $variant
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'variant_id', 'sku', 'variant_label', 'barcode', 'name', 'unit', 'price', 'qty', 'total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'total' => 'decimal:2',
            'qty' => 'integer',
            'stock_applied_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * The name with the variant snapshot, «Футболка (Цвет: красный, Размер: M)» (VR-12); the plain name without one.
     */
    public function displayName(): string
    {
        return $this->variant_label !== null && $this->variant_label !== '' ? $this->name.' ('.$this->variant_label.')' : $this->name;
    }
}
