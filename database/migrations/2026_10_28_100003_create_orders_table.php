<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 32)->unique();
            $table->string('type', 16)->index();
            $table->string('status', 16)->index();
            $table->string('customer_name');
            $table->string('customer_phone', 32)->index();
            $table->string('customer_email')->nullable();
            $table->string('delivery_method')->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_price', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('items_total', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->text('customer_comment')->nullable();
            $table->text('manager_comment')->nullable();
            $table->dateTime('reserved_until')->nullable()->index();
            $table->date('desired_date')->nullable();
            // Consent to personal data processing: fact, time and IP (SF-71).
            $table->dateTime('consent_at');
            $table->string('consent_ip', 45);
            $table->string('user_agent', 500)->nullable();
            // Whether stock_qty / reserved_qty were changed for this request (Appendix B, D-046).
            $table->boolean('stock_applied')->default(false);
            // Technical: "reservation expires soon" mail was sent (stage 6).
            $table->dateTime('expiring_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
