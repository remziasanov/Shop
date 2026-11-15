<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 33.3 (PV-08): phone verification records plus `customers.phone_verified_at` and the request snapshot
 * `orders.phone_verified`.
 *
 *  - `phone_verifications.phone` is +7XXXXXXXXXX; `code_hash` is the HMAC of the code (null for records without a sent
 *    code: skipped and blocked); `session_hash` binds the code to the session that asked for it (D-108).
 *  - `cost` is decimal(12,4): the explicit exception to decimal(12,2) of PV-08 (providers report fractions of a kopeck).
 *  - `note` holds the reason of a skipped or blocked record (failure kind, `daily_limit`, `balance`).
 *  - Indexes: the daily limit counts today's accepted rows by `created_at`; `verify` looks up open rows by number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 16);
            $table->string('purpose', 30);
            $table->string('method', 20);
            $table->char('code_hash', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('verified_at')->nullable();
            $table->string('outcome', 20);
            $table->char('session_hash', 64)->nullable();
            $table->string('provider_request_id', 64)->nullable();
            $table->decimal('cost', 12, 4)->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('note', 40)->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose', 'outcome'], 'phone_verifications_lookup_index');
            $table->index('created_at');
            $table->index('customer_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dateTime('phone_verified_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('phone_verified')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('phone_verified');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('phone_verified_at');
        });

        Schema::dropIfExists('phone_verifications');
    }
};
