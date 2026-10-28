<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CA-03, CA-31, 17.5: storefront customers, separate from the staff `users` (D-071). `auth_version` is compared with
 * the value kept in the session by the `customer` guard middleware (D-089).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->restrictOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('phone', 32)->nullable()->index();
            $table->string('password');
            $table->rememberToken();
            $table->unsignedInteger('auth_version')->default(1);
            $table->boolean('is_blocked')->default(false);
            // Visible to staff only (CA-21).
            $table->text('blocked_reason')->nullable();
            // Consent to personal data processing: time and IP (CA-02).
            $table->dateTime('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('deletion_requested_at')->nullable();
            $table->dateTime('anonymized_at')->nullable();
            $table->timestamps();

            // shop:purge-unverified-customers (CA-24) and the «new registrations» widget.
            $table->index(['email_verified_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
