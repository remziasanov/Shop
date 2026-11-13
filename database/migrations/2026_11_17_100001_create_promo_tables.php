<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 22.5: promo codes, their groups / targets / batches / redemptions, promo links and their daily click counters.
 * `promo_redemptions.customer_hash` is char(64): the HMAC-SHA256 hex digest of PM-10 (the TZ table says 40, which
 * cannot hold a SHA-256 digest). Redemptions restrict the deletion of a code (PM-19); links and orders only lose
 * the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('prefix', 20)->default('');
            $table->unsignedInteger('count');
            $table->unsignedTinyInteger('code_length');
            $table->json('template')->nullable();
            $table->unsignedInteger('generated')->default(0);
            $table->string('status', 16)->default('queued');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 16);
            $table->decimal('value', 12, 2)->nullable();
            $table->string('scope', 16)->default('all');
            $table->boolean('include_subcategories')->default(true);
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->boolean('only_registered')->default(false);
            $table->boolean('applies_to_discounted')->default(false);
            $table->boolean('stackable_with_promotions')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('batch_id')->nullable()->constrained('promo_code_batches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('code');
            $table->index(['is_active', 'ends_at']);
        });

        Schema::create('promo_code_groups', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();

            $table->primary(['promo_code_id', 'customer_group_id']);
        });

        Schema::create('promo_code_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_value')->nullable();

            $table->index(['promo_code_id', 'target_type']);
        });

        Schema::create('promo_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->char('customer_hash', 64)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->dateTime('released_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->unique('order_id');
            $table->index(['promo_code_id', 'customer_id']);
            $table->index(['promo_code_id', 'customer_hash']);
        });

        Schema::create('promo_links', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('target_url', 500)->nullable();
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('slug');
            $table->index('is_active');
        });

        Schema::create('promo_link_stats', function (Blueprint $table): void {
            $table->foreignId('promo_link_id')->constrained('promo_links')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);

            $table->primary(['promo_link_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_link_stats');
        Schema::dropIfExists('promo_links');
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_code_targets');
        Schema::dropIfExists('promo_code_groups');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('promo_code_batches');
    }
};
