<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use Carbon\CarbonInterface;
use Database\Factories\TelegramMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An outgoing Telegram message (TN-07): written before sending, so a repeated job never sends it twice (`dedupe_key`
 * is unique). `chat_id` is set for the work chat (signed: group ids are negative). `error` never holds a token or
 * personal data.
 *
 * @property int $id
 * @property int|null $telegram_account_id
 * @property int|null $chat_id
 * @property TelegramMessageKind $kind
 * @property int|null $order_id
 * @property string $dedupe_key
 * @property int|null $message_id Telegram message id
 * @property TelegramMessageStatus $status
 * @property string|null $error
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read TelegramAccount|null $account
 * @property-read Order|null $order
 */
class TelegramMessage extends Model
{
    /** @use HasFactory<TelegramMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'telegram_account_id',
        'chat_id',
        'kind',
        'order_id',
        'dedupe_key',
        'message_id',
        'status',
        'error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_account_id' => 'integer',
            'chat_id' => 'integer',
            'kind' => TelegramMessageKind::class,
            'order_id' => 'integer',
            'message_id' => 'integer',
            'status' => TelegramMessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
