<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily housekeeping of the Telegram tables (TG-12). Every table is deleted in chunks until a deadline, so a long
 * backlog never makes the cron job exceed its limit; the rest is taken on the next run.
 */
class TelegramPruner
{
    public const UPDATES_DAYS = 7;

    public const MESSAGES_DAYS = 90;

    public const ACCOUNT_INACTIVE_MONTHS = 12;

    private const CHUNK = 1000;

    /**
     * @return array{updates: int, tokens: int, messages: int, accounts: int}
     */
    public function run(?float $deadline = null): array
    {
        $deadline ??= microtime(true) + 35;

        return [
            'updates' => $this->deleteChunked(TelegramUpdate::query()->where('received_at', '<', now()->subDays(self::UPDATES_DAYS)), 'id', $deadline),
            'tokens' => $this->deleteChunked(TelegramLinkToken::query()->where(fn (Builder $q) => $q->whereNotNull('used_at')->orWhere('expires_at', '<', now())), 'id', $deadline),
            'messages' => $this->pruneMessages($deadline),
            'accounts' => $this->deleteChunked($this->staleAccounts(), 'id', $deadline),
        ];
    }

    /**
     * Rows without a customer and a staff user, silent for 12 months and without an unfinished request. Their
     * requests keep living: `orders.telegram_account_id` is set to null by the foreign key.
     *
     * @return Builder<TelegramAccount>
     */
    public function staleAccounts(): Builder
    {
        $border = now()->subMonths(self::ACCOUNT_INACTIVE_MONTHS);
        $unfinished = array_map(fn (OrderStatus $status): string => $status->value, array_filter(OrderStatus::cases(), fn (OrderStatus $status): bool => ! $status->isFinal()));

        return TelegramAccount::query()
            ->whereNull('customer_id')
            ->whereNull('user_id')
            ->where(fn (Builder $q) => $q->where('last_seen_at', '<', $border)->orWhere(fn (Builder $inner) => $inner->whereNull('last_seen_at')->where('created_at', '<', $border)))
            ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('orders')
                ->whereColumn('orders.telegram_account_id', 'telegram_accounts.id')
                ->whereIn('orders.status', $unfinished));
    }

    private function pruneMessages(float $deadline): int
    {
        // telegram_messages arrives with stage 18 (TN-07).
        if (! Schema::hasTable('telegram_messages')) {
            return 0;
        }

        $query = DB::table('telegram_messages')->where('created_at', '<', now()->subDays(self::MESSAGES_DAYS));
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('telegram_messages')->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::CHUNK && microtime(true) < $deadline);

        return $deleted;
    }

    /**
     * @param  Builder<*>  $query
     */
    private function deleteChunked(Builder $query, string $key, float $deadline): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck($key);
            $deleted += $ids->isEmpty() ? 0 : $query->getModel()->newQuery()->whereIn($key, $ids)->delete();
        } while ($ids->count() === self::CHUNK && microtime(true) < $deadline);

        return $deleted;
    }
}
