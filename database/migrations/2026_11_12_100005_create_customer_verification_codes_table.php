<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CA-04, 17.6: one-time e-mail codes. Only the HMAC of a code is stored. `customer_id` is null for a registration
 * that has no account yet. `payload` holds the new address of a change-email request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('email');
            // register / login / password_reset / change_email / delete_account
            $table->string('purpose', 24);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('sent_at');
            // A consumed or invalidated code (a new one was issued, too many wrong attempts).
            $table->dateTime('consumed_at')->nullable();
            $table->string('ip', 45);
            $table->json('payload')->nullable();
            $table->dateTime('created_at');

            $table->index(['email', 'purpose']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_verification_codes');
    }
};
