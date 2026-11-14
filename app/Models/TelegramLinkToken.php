<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TelegramLinkPurpose;
use Carbon\CarbonInterface;
use Database\Factories\TelegramLinkTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time deep-link token into the bot (TG-07). Only the SHA-256 hex of the token is stored.
 *
 * @property int $id
 * @property string $token_hash
 * @property TelegramLinkPurpose $purpose
 * @property int|null $customer_id
 * @property int|null $user_id
 * @property list<int>|null $order_ids the requests of one checkout (purpose `order`)
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $used_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class TelegramLinkToken extends Model
{
    /** @use HasFactory<TelegramLinkTokenFactory> */
    use HasFactory;

    protected $fillable = ['token_hash', 'purpose', 'customer_id', 'user_id', 'order_ids', 'expires_at', 'used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => TelegramLinkPurpose::class,
            'order_ids' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Not used and not expired.
     *
     * @param  Builder<TelegramLinkToken>  $query
     * @return Builder<TelegramLinkToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
