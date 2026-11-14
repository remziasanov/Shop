<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgeRestriction;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Services\Stock\StockService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Customer request: order, reservation or inquiry (TZ 6.9). Items hold a snapshot of the products (principle 4).
 * Money columns are decimal(12,2) strings; use {@see Money} for arithmetic.
 * Everything is written by `OrderService`, so only data columns are fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property OrderType $type
 * @property OrderStatus $status
 * @property string $customer_name
 * @property string $customer_phone
 * @property string|null $customer_email
 * @property string|null $delivery_method name of the chosen delivery method (snapshot)
 * @property string|null $delivery_address
 * @property string $delivery_price
 * @property string|null $payment_method name of the chosen payment method (snapshot)
 * @property string|null $items_total
 * @property string|null $total
 * @property string|null $customer_comment
 * @property string|null $manager_comment
 * @property CarbonInterface|null $reserved_until
 * @property CarbonInterface|null $desired_date
 * @property CarbonInterface $consent_at
 * @property string $consent_ip
 * @property string|null $user_agent
 * @property bool $stock_applied
 * @property AgeRestriction|null $age_confirmed_level age the customer confirmed at checkout (AG-11)
 * @property int|null $customer_id the signed-in customer who placed the request; null for a guest (CA-14, CA-26)
 * @property int|null $customer_group_id snapshot of the customer's group at that moment (CA-26)
 * @property string|null $customer_group_name name of that group (survives a later change or deletion of the group)
 * @property CarbonInterface|null $expiring_notified_at
 * @property int|null $promo_code_id PM-18: the promo code of the order (null once the code is deleted)
 * @property string|null $promo_code PM-18: snapshot of the code text
 * @property string $promo_discount PM-18: discount of the promo code (decimal string)
 * @property string $manual_discount discount typed in by a manager (decimal string)
 * @property int|null $promo_link_id PM-24: the promo link the order is attributed to (last click)
 * @property int|null $related_order_id MR-05: the other request (order or reservation) of the same checkout
 * @property bool $reserved_via_cart MR-12: a reservation made from the cart
 * @property int $version OE-08: optimistic lock of the order editor, moved by every edit and status change
 * @property OrderChannel $channel TM-14: web, pwa or telegram
 * @property int|null $telegram_account_id TM-05
 * @property int|null $source_order_id OE-12: the inquiry this order was created from
 * @property int|null $updated_by_user_id the staff member of the last edit or status change (OE-08)
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, OrderChange> $editLog
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $statusHistory
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'number',
        'type',
        'status',
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_method',
        'delivery_address',
        'delivery_price',
        'payment_method',
        'items_total',
        'total',
        'customer_comment',
        'manager_comment',
        'reserved_until',
        'desired_date',
        'consent_at',
        'consent_ip',
        'user_agent',
        'age_confirmed_level',
        'channel',
        'telegram_account_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });

        // Safety net (D-051): a raw delete of an active request must not keep its stock held. The normal path
        // is OrderService::delete(), which also writes the history row; this call is then a no-op.
        static::deleting(function (Order $order): void {
            app(StockService::class)->settleForDeletion($order);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'delivery_price' => 'decimal:2',
            'items_total' => 'decimal:2',
            'total' => 'decimal:2',
            'reserved_until' => 'datetime',
            'desired_date' => 'date',
            'consent_at' => 'datetime',
            'stock_applied' => 'boolean',
            'age_confirmed_level' => AgeRestriction::class,
            'channel' => OrderChannel::class,
            'customer_id' => 'integer',
            'customer_group_id' => 'integer',
            'expiring_notified_at' => 'datetime',
            'promo_code_id' => 'integer',
            'promo_discount' => 'decimal:2',
            'manual_discount' => 'decimal:2',
            'promo_link_id' => 'integer',
            'related_order_id' => 'integer',
            'reserved_via_cart' => 'boolean',
            'version' => 'integer',
            'source_order_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    /**
     * The public "Thank you" page is addressed by UUID (SF-47).
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The customer account the request belongs to (null for guest requests).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The Telegram account the request was placed from or follows (TM-05, TG-08).
     *
     * @return BelongsTo<TelegramAccount, $this>
     */
    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    /**
     * The order / reservation made by the same checkout (MR-05); null for a single request.
     *
     * @return BelongsTo<Order, $this>
     */
    public function relatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'related_order_id');
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return BelongsTo<PromoLink, $this>
     */
    public function promoLink(): BelongsTo
    {
        return $this->belongsTo(PromoLink::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /**
     * The change log of the order editor (OE-07), oldest first.
     *
     * @return HasMany<OrderChange, $this>
     */
    public function editLog(): HasMany
    {
        return $this->hasMany(OrderChange::class)->orderBy('id');
    }

    /**
     * The inquiry this order was created from (OE-12).
     *
     * @return BelongsTo<Order, $this>
     */
    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id')->withTrashed();
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeOfType(Builder $query, OrderType $type): void
    {
        $query->where($query->qualifyColumn('type'), $type->value);
    }

    /**
     * Requests in the `new` status (the counter in the admin menu, AD-04).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeNew(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), OrderStatus::New->value);
    }

    /**
     * Active reservations whose term ends within the next `$hours` hours (AD-10, stage 6 notifications).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $hours): void
    {
        $query->where($query->qualifyColumn('type'), OrderType::Reservation->value)
            ->whereIn($query->qualifyColumn('status'), [OrderStatus::New->value, OrderStatus::Reserved->value])
            ->where($query->qualifyColumn('reserved_until'), '>', now())
            ->where($query->qualifyColumn('reserved_until'), '<=', now()->addHours($hours));
    }

    /**
     * Reservations that are active but past their term (to be expired by the scheduler).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeOverdueReservations(Builder $query): void
    {
        $query->where($query->qualifyColumn('type'), OrderType::Reservation->value)
            ->whereIn($query->qualifyColumn('status'), [OrderStatus::New->value, OrderStatus::Reserved->value])
            ->where($query->qualifyColumn('reserved_until'), '<', now());
    }
}
