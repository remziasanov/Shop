<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Guest cart identified by the cookie token (SF-40, D-047).
 *
 * @property int $id
 * @property string $token
 * @property int|null $customer_id the owner of a customer cart (CA-15; unique); null for a guest cart (cookie token)
 * @property int|null $telegram_account_id TM-10: the Telegram account of a guest cart in Telegram mode
 * @property string|null $promo_code PM-13: the applied promo code (normalized text)
 * @property bool $promo_applied_manually PM-23: the customer typed the code (an auto-applied one removed by hand is not re-applied)
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    protected $fillable = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['promo_applied_manually' => 'boolean'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<TelegramAccount, $this>
     */
    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
