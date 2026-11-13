<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-12: where the price of an order line came from (tier / group_price / group_discount / retail), the tier and the
 * retail price at the time of the order. GP-16: the group and the tier quantity of a price history row (field
 * group_price / group_tier); the group FK is SET NULL, the history outlives the group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('price_source', 16)->nullable();
            $table->unsignedInteger('tier_min_qty')->nullable();
            $table->decimal('retail_price', 12, 2)->nullable();
        });

        Schema::table('price_history', function (Blueprint $table): void {
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->unsignedInteger('min_qty')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('price_history', function (Blueprint $table): void {
            $table->dropForeign(['customer_group_id']);
        });

        Schema::table('price_history', function (Blueprint $table): void {
            $table->dropColumn(['customer_group_id', 'min_qty']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['price_source', 'tier_min_qty', 'retail_price']);
        });
    }
};
