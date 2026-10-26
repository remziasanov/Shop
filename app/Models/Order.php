<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property CarbonInterface|null $expiring_notified_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
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
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
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
            'expiring_notified_at' => 'datetime',
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
