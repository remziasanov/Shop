<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 32.1 (TN-07): `telegram_messages`, the log of outgoing Telegram messages used for deduplication and for editing
 * the shop's messages. `chat_id` is a signed bigint (group ids are negative).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_account_id')->nullable()->constrained('telegram_accounts')->cascadeOnDelete();
            $table->bigInteger('chat_id')->nullable();
            $table->string('kind', 40);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('dedupe_key', 191)->unique();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
