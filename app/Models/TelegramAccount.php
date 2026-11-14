<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TelegramAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram user known to the shop (TG-06). `first_name`, `last_name`, `username` and `phone` are personal data:
 * masked in logs (NF-15) and erased with the customer (CA-17, see CustomerAnonymizer). One Telegram links to at most one
 * customer and one staff user.
 *
 * @property int $id
 * @property int $telegram_user_id
 * @property int|null $customer_id
 * @property int|null $customer_auth_version `customers.auth_version` at linking time (TM-04)
 * @property int|null $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $username
 * @property string|null $language_code
 * @property string|null $phone
 * @property CarbonInterface|null $phone_verified_at
 * @property CarbonInterface|null $chat_started_at the bot may write (was /start or write access allowed)
 * @property CarbonInterface|null $bot_blocked_at
 * @property bool $notify_enabled
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Customer|null $customer
 * @property-read User|null $user
 */
class TelegramAccount extends Model
{
    /** @use HasFactory<TelegramAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'customer_id',
        'customer_auth_version',
        'user_id',
        'first_name',
        'last_name',
        'username',
        'language_code',
        'phone',
        'phone_verified_at',
        'chat_started_at',
        'bot_blocked_at',
        'notify_enabled',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'customer_id' => 'integer',
            'customer_auth_version' => 'integer',
            'user_id' => 'integer',
            'phone_verified_at' => 'datetime',
            'chat_started_at' => 'datetime',
            'bot_blocked_at' => 'datetime',
            'notify_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The bot may message this user: the chat was started, the bot is not blocked and notifications are on (TN-01).
     */
    public function canMessage(): bool
    {
        return $this->chat_started_at !== null && $this->bot_blocked_at === null && $this->notify_enabled;
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

    /**
     * @return HasMany<Cart, $this>
     */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
