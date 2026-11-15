<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\StatusChangeSource;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Models\AdminAuditEntry;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Telegram\TelegramPruner;
use App\Services\Users\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 18 shared foundation: TN-07 telegram_messages, TS-03 / TS-07 source columns, TS-08 personal notify flag.
 */
function sharedFoundationMigration(string $file): object
{
    return require database_path('migrations/'.$file);
}

describe('migrations', function (): void {
    it('creates telegram_messages with a unique dedupe key and a signed chat id', function (): void {
        expect(Schema::hasTable('telegram_messages'))->toBeTrue();

        TelegramMessage::factory()->create(['dedupe_key' => 'k1', 'chat_id' => -1001234567890]);
        expect(TelegramMessage::query()->where('dedupe_key', 'k1')->value('chat_id'))->toBe(-1001234567890);

        expect(fn () => TelegramMessage::factory()->create(['dedupe_key' => 'k1']))->toThrow(QueryException::class);
    });

    it('cascades messages with the account and nulls the order link', function (): void {
        $order = Order::factory()->create();
        $account = TelegramAccount::factory()->create();
        $message = TelegramMessage::factory()->create(['telegram_account_id' => $account->id, 'order_id' => $order->id]);

        $order->forceDelete();
        expect($message->refresh()->order_id)->toBeNull();

        $account->delete();
        expect(TelegramMessage::query()->count())->toBe(0);
    });

    it('backfills the source of existing rows and is reversible', function (): void {
        $migration = sharedFoundationMigration('2026_11_23_100002_add_telegram_columns_to_users_history_and_audit_log.php');
        $migration->down();

        expect(Schema::hasColumn('order_status_history', 'source'))->toBeFalse()
            ->and(Schema::hasColumn('admin_audit_log', 'source'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'telegram_personal_notify'))->toBeFalse();

        $order = Order::factory()->create();
        $user = User::factory()->create();
        $row = ['order_id' => $order->id, 'to_status' => 'new', 'created_at' => now()];
        DB::table('order_status_history')->insert([$row + ['user_id' => $user->id], $row + ['user_id' => null]]);
        DB::table('admin_audit_log')->insert(['action' => 'role.created', 'created_at' => now()]);

        $migration->up();

        expect(DB::table('order_status_history')->where('order_id', $order->id)->whereNotNull('user_id')->value('source'))->toBe('admin')
            ->and(DB::table('order_status_history')->where('order_id', $order->id)->whereNull('user_id')->value('source'))->toBe('system')
            ->and(DB::table('admin_audit_log')->value('source'))->toBe('admin')
            ->and((bool) DB::table('users')->where('id', $user->id)->value('telegram_personal_notify'))->toBeFalse();
    });

    it('drops telegram_messages on down()', function (): void {
        $migration = sharedFoundationMigration('2026_11_23_100001_create_telegram_messages_table.php');
        $migration->down();
        expect(Schema::hasTable('telegram_messages'))->toBeFalse();
        $migration->up();
        expect(Schema::hasTable('telegram_messages'))->toBeTrue();
    });
});

describe('enums and model', function (): void {
    it('has the TZ cases with Russian labels', function (): void {
        expect(array_map(fn ($c) => $c->value, TelegramMessageKind::cases()))->toBe([
            'order_created', 'status_changed', 'reservation_expiring', 'reservation_expired', 'items_changed',
            'wholesale_decision', 'export_ready', 'staff_new_order', 'staff_order_action',
        ])
            ->and(array_map(fn ($c) => $c->value, TelegramMessageStatus::cases()))->toBe(['queued', 'sent', 'failed', 'skipped'])
            ->and(array_map(fn ($c) => $c->value, StatusChangeSource::cases()))->toBe(['admin', 'telegram', 'customer', 'system']);

        foreach ([...TelegramMessageKind::cases(), ...TelegramMessageStatus::cases(), ...StatusChangeSource::cases()] as $case) {
            expect($case->label())->not->toStartWith('telegram.')->and($case->label())->toMatch('/\p{Cyrillic}|Telegram/u');
        }
    });

    it('casts and relates the message', function (): void {
        $order = Order::factory()->create();
        $account = TelegramAccount::factory()->create();
        $message = TelegramMessage::factory()->sent()->create(['telegram_account_id' => $account->id, 'order_id' => $order->id]);

        $message->refresh();
        expect($message->kind)->toBe(TelegramMessageKind::StatusChanged)
            ->and($message->status)->toBe(TelegramMessageStatus::Sent)
            ->and($message->sent_at)->not->toBeNull()
            ->and($message->account->is($account))->toBeTrue()
            ->and($message->order->is($order))->toBeTrue()
            ->and($order->telegramMessages()->count())->toBe(1)
            ->and($account->messages()->count())->toBe(1);
    });

    it('casts users.telegram_personal_notify to bool, off by default', function (): void {
        $user = User::factory()->create();
        expect($user->refresh()->telegram_personal_notify)->toBeFalse();
        $user->forceFill(['telegram_personal_notify' => true])->save();
        expect($user->refresh()->telegram_personal_notify)->toBeTrue();
    });
});

describe('source of status changes', function (): void {
    it('stores admin for a staff change and system without a user', function (): void {
        $order = Order::factory()->status(OrderStatus::New)->create();
        $staff = User::factory()->create();

        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff);
        app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled);

        $history = OrderStatusHistory::query()->where('order_id', $order->id)->orderBy('id')->get();
        expect($history[0]->source)->toBe(StatusChangeSource::Admin)
            ->and($history[1]->source)->toBe(StatusChangeSource::System);
    });

    it('stores an explicit telegram or customer source', function (): void {
        $staff = User::factory()->create();
        $order = Order::factory()->status(OrderStatus::New)->create();
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff, null, null, StatusChangeSource::Telegram);

        $other = Order::factory()->status(OrderStatus::New)->create();
        app(OrderService::class)->changeStatus($other, OrderStatus::Cancelled, null, null, null, StatusChangeSource::Customer);

        expect(OrderStatusHistory::query()->where('order_id', $order->id)->value('source'))->toBe(StatusChangeSource::Telegram)
            ->and(OrderStatusHistory::query()->where('order_id', $other->id)->value('source'))->toBe(StatusChangeSource::Customer);
    });
});

describe('audit source', function (): void {
    it('defaults to admin and accepts telegram', function (): void {
        $user = User::factory()->create();
        $logger = app(AuditLogger::class);

        $logger->record(AuditAction::UserUpdated, $user);
        $logger->record(AuditAction::UserUpdated, $user, null, [], StatusChangeSource::Telegram);

        $sources = AdminAuditEntry::query()->orderBy('id')->get()->map(fn ($e) => $e->source)->all();
        expect($sources)->toBe([StatusChangeSource::Admin, StatusChangeSource::Telegram]);
    });
});

describe('pruner', function (): void {
    it('deletes telegram_messages older than 90 days only', function (): void {
        $old = TelegramMessage::factory()->create(['created_at' => now()->subDays(91)]);
        $fresh = TelegramMessage::factory()->create(['created_at' => now()->subDays(89)]);

        $result = app(TelegramPruner::class)->run();

        expect($result['messages'])->toBe(1)
            ->and(TelegramMessage::query()->pluck('id')->all())->toBe([$fresh->id])
            ->and(TelegramMessage::query()->whereKey($old->id)->exists())->toBeFalse();
    });
});
