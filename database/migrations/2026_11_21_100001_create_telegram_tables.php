<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 32.1 (TG-06, TG-07, TG-04, TM-10, TM-05, TN-05): the data foundation of the Telegram bot and Mini App.
 *
 *  - `telegram_accounts`: a Telegram user known to the shop (bigint ids). One Telegram is linked to at most one customer
 *    and one staff user, one customer / user to at most one Telegram (unique FK columns). `customer_auth_version` is the
 *    `customers.auth_version` seen at linking time (TM-04); `customers.auth_version` already exists (stage 9, CA-31).
 *  - `telegram_link_tokens`: one-time deep-link tokens, only the SHA-256 hex of the token is stored.
 *  - `telegram_updates`: processed update ids for webhook deduplication; no body is stored.
 *  - `carts.telegram_account_id`, `orders.telegram_account_id`, `customers.telegram_notify_events`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->foreignId('customer_id')->nullable()->unique()->constrained('customers')->nullOnDelete();
            $table->unsignedInteger('customer_auth_version')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->dateTime('phone_verified_at')->nullable();
            $table->dateTime('chat_started_at')->nullable();
            $table->dateTime('bot_blocked_at')->nullable();
            $table->boolean('notify_enabled')->default(true);
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 20);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->json('order_ids')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'expires_at']);
        });

        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('type', 40);
            $table->dateTime('received_at');

            $table->index('received_at');
        });

        // SQLite (tests) accepts a foreign key on an added nullable column, as in the promo columns migration.
        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('telegram_account_id')->nullable()->constrained('telegram_accounts')->cascadeOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('telegram_account_id')->nullable()->constrained('telegram_accounts')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->json('telegram_notify_events')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('telegram_notify_events');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['telegram_account_id']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('telegram_account_id');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign(['telegram_account_id']);
        });
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('telegram_account_id');
        });

        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_link_tokens');
        Schema::dropIfExists('telegram_accounts');
    }
};
