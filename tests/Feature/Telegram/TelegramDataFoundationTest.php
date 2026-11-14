<?php

declare(strict_types=1);

use App\Enums\TelegramLinkPurpose;
use App\Models\Cart;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Customers\CustomerAnonymizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * TG-06, TG-07, TG-04, TM-10, TN-05 (TZ 32.1): tables, models and the anonymization of the Telegram account.
 */
const TELEGRAM_MIGRATION = '2026_11_21_100001_create_telegram_tables';

it('creates and drops the schema reversibly', function (): void {
    expect(Schema::hasTable('telegram_accounts'))->toBeTrue()
        ->and(Schema::hasColumns('telegram_accounts', ['telegram_user_id', 'customer_id', 'customer_auth_version', 'user_id', 'phone', 'chat_started_at', 'bot_blocked_at', 'notify_enabled', 'last_seen_at']))->toBeTrue()
        ->and(Schema::hasColumns('telegram_link_tokens', ['token_hash', 'purpose', 'customer_id', 'user_id', 'order_ids', 'expires_at', 'used_at']))->toBeTrue()
        ->and(Schema::hasColumns('telegram_updates', ['update_id', 'type', 'received_at']))->toBeTrue()
        ->and(Schema::hasColumn('carts', 'telegram_account_id'))->toBeTrue()
        ->and(Schema::hasColumn('orders', 'telegram_account_id'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'telegram_notify_events'))->toBeTrue();

    $migration = require database_path('migrations/'.TELEGRAM_MIGRATION.'.php');
    $migration->down();

    expect(Schema::hasTable('telegram_accounts'))->toBeFalse()
        ->and(Schema::hasTable('telegram_link_tokens'))->toBeFalse()
        ->and(Schema::hasTable('telegram_updates'))->toBeFalse()
        ->and(Schema::hasColumn('carts', 'telegram_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('orders', 'telegram_account_id'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'telegram_notify_events'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('telegram_accounts'))->toBeTrue()
        ->and(Schema::hasColumn('orders', 'telegram_account_id'))->toBeTrue();
});

it('relates accounts to customers, staff, carts and orders', function (): void {
    $customer = makeCustomer();
    $user = User::factory()->create();
    $account = TelegramAccount::factory()->create(['customer_id' => $customer->id, 'user_id' => $user->id]);
    $cart = Cart::factory()->create(['telegram_account_id' => $account->id]);
    $order = Order::factory()->create(['telegram_account_id' => $account->id]);

    expect($account->customer->is($customer))->toBeTrue()
        ->and($account->user->is($user))->toBeTrue()
        ->and($customer->telegramAccount->is($account))->toBeTrue()
        ->and($user->telegramAccount->is($account))->toBeTrue()
        ->and($account->carts->pluck('id')->all())->toBe([$cart->id])
        ->and($account->orders->pluck('id')->all())->toBe([$order->id])
        ->and($cart->fresh()->telegramAccount->is($account))->toBeTrue()
        ->and($order->fresh()->telegramAccount->is($account))->toBeTrue();
});

it('stores telegram ids above the 32-bit range', function (): void {
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 6_000_000_000]);

    expect($account->fresh()->telegram_user_id)->toBe(6_000_000_000);
});

it('keeps one Telegram per customer, per staff user and per telegram id', function (): void {
    $customer = makeCustomer();
    $user = User::factory()->create();
    TelegramAccount::factory()->create(['telegram_user_id' => 111, 'customer_id' => $customer->id, 'user_id' => $user->id]);

    expect(fn () => TelegramAccount::factory()->create(['telegram_user_id' => 111]))->toThrow(QueryException::class)
        ->and(fn () => TelegramAccount::factory()->create(['customer_id' => $customer->id]))->toThrow(QueryException::class)
        ->and(fn () => TelegramAccount::factory()->create(['user_id' => $user->id]))->toThrow(QueryException::class);

    // Several accounts without an owner are fine (NULL is not unique).
    TelegramAccount::factory()->count(2)->create();
});

it('keeps update ids and token hashes unique', function (): void {
    TelegramUpdate::factory()->create(['update_id' => 5]);
    TelegramLinkToken::factory()->create(['token_hash' => str_repeat('a', 64)]);

    expect(fn () => TelegramUpdate::factory()->create(['update_id' => 5]))->toThrow(QueryException::class)
        ->and(fn () => TelegramLinkToken::factory()->create(['token_hash' => str_repeat('a', 64)]))->toThrow(QueryException::class)
        ->and(TelegramUpdate::query()->first()->getAttributes())->not->toHaveKey('updated_at');
});

it('selects only usable link tokens and casts the order ids', function (): void {
    $usable = TelegramLinkToken::factory()->create(['purpose' => TelegramLinkPurpose::Order, 'order_ids' => [3, 4], 'expires_at' => now()->addDays(7)]);
    TelegramLinkToken::factory()->expired()->create();
    TelegramLinkToken::factory()->used()->create();

    expect(TelegramLinkToken::query()->usable()->pluck('id')->all())->toBe([$usable->id])
        ->and($usable->fresh()->order_ids)->toBe([3, 4])
        ->and($usable->fresh()->purpose)->toBe(TelegramLinkPurpose::Order)
        ->and($usable->purpose->label())->not->toBe('telegram.link_purpose.order')
        ->and($usable->purpose->prefix())->toBe('o_');
});

it('can message only a started, unblocked, subscribed chat', function (): void {
    expect(TelegramAccount::factory()->chatStarted()->make()->canMessage())->toBeTrue()
        ->and(TelegramAccount::factory()->make()->canMessage())->toBeFalse()
        ->and(TelegramAccount::factory()->chatStarted()->blocked()->make()->canMessage())->toBeFalse()
        ->and(TelegramAccount::factory()->chatStarted()->make(['notify_enabled' => false])->canMessage())->toBeFalse();
});

it('casts the notify events of a customer', function (): void {
    $customer = makeCustomer();
    $customer->forceFill(['telegram_notify_events' => ['status_changed']])->save();

    expect($customer->fresh()->telegram_notify_events)->toBe(['status_changed']);
});

it('deletes the telegram account when the customer is anonymized', function (): void {
    $customer = makeCustomer();
    $account = TelegramAccount::factory()->withPhone()->create(['customer_id' => $customer->id]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'telegram_account_id' => $account->id]);
    $token = TelegramLinkToken::factory()->create(['customer_id' => $customer->id]);

    app(CustomerAnonymizer::class)->anonymize($customer);

    expect(TelegramAccount::query()->count())->toBe(0)
        ->and(TelegramLinkToken::query()->whereKey($token->id)->exists())->toBeFalse()
        ->and($order->fresh()->telegram_account_id)->toBeNull();
});

it('keeps the account for a linked staff user and clears the customer side', function (): void {
    $customer = makeCustomer();
    $user = User::factory()->create();
    $account = TelegramAccount::factory()->withPhone()->create([
        'customer_id' => $customer->id, 'customer_auth_version' => 3, 'user_id' => $user->id,
    ]);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'telegram_account_id' => $account->id]);

    app(CustomerAnonymizer::class)->anonymize($customer);

    $account->refresh();
    expect($account->customer_id)->toBeNull()
        ->and($account->customer_auth_version)->toBeNull()
        ->and($account->phone)->toBeNull()
        ->and($account->phone_verified_at)->toBeNull()
        ->and($account->user_id)->toBe($user->id)
        ->and($order->fresh()->telegram_account_id)->toBeNull();
});

it('deletes the guest carts of an account with it', function (): void {
    $account = TelegramAccount::factory()->create();
    Cart::factory()->create(['telegram_account_id' => $account->id]);

    $account->delete();

    expect(Cart::query()->count())->toBe(0);
});
