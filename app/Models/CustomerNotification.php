<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CustomerNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notification shown in the customer account (CA-18). Has no `updated_at`; created by the notification service of
 * the cabinet (`customer_id` and `type` are set explicitly there).
 *
 * @property int $id
 * @property int $customer_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string|null $url
 * @property string|null $dedupe_key
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['customer_id', 'type', 'title', 'body', 'url', 'dedupe_key'])]
class CustomerNotification extends Model
{
    /** @use HasFactory<CustomerNotificationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** CA-18: how long notifications are kept. */
    public const RETENTION_DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<CustomerNotification>  $query
     */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('read_at'));
    }
}
