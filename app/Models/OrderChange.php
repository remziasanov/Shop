<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderChangeKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the immutable change log of an order (OE-07): a field or an item, old and new value, who and when.
 * Rows of one save share `batch_uuid`. Written only by `OrderEditor`; there is no interface to edit them.
 *
 * @property int $id
 * @property int $order_id
 * @property string $batch_uuid
 * @property OrderChangeKind $kind
 * @property string|null $field
 * @property int|null $order_item_id
 * @property string|null $item_label snapshot of the item name and article
 * @property string|null $old_value
 * @property string|null $new_value
 * @property int|null $user_id
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read User|null $user
 */
class OrderChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'batch_uuid', 'kind', 'field', 'order_item_id', 'item_label', 'old_value', 'new_value', 'user_id', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => OrderChangeKind::class,
            'order_item_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
