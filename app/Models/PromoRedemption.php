<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PromoRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a promo code by an order (PM-11, PM-12): unique per order; `released_at` marks a use given back.
 *
 * @property int $id
 * @property int $promo_code_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property string|null $customer_hash HMAC-SHA256 hex digest (PM-10)
 * @property string $discount_amount
 * @property CarbonInterface|null $released_at
 * @property CarbonInterface|null $created_at
 */
class PromoRedemption extends Model
{
    /** @use HasFactory<PromoRedemptionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['promo_code_id', 'order_id', 'customer_id', 'customer_hash', 'discount_amount', 'released_at'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['discount_amount' => 'decimal:2', 'released_at' => 'datetime', 'customer_id' => 'integer'];
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
