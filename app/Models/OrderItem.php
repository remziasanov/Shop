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
 * @property string $sku
 * @property string $name
 * @property string $unit
 * @property string|null $price
 * @property int $qty
 * @property string|null $total
 * @property int $stock_applied_qty
 * @property-read Order $order
 * @property-read Product|null $product
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = ['order_id', 'product_id', 'sku', 'name', 'unit', 'price', 'qty', 'total'];

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
}
