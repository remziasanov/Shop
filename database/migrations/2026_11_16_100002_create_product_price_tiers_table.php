<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-02: quantity tiers (min_qty >= 2) and the exact group price (min_qty = 1) of a product or a variant for a customer
 * group. `variant_key` = COALESCE(variant_id, 0) is the technical column of the unique index (as in cart_items, VR-12),
 * maintained by the ProductPriceTier model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('variant_key')->default(0);
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->unsignedInteger('min_qty');
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['product_id', 'variant_key', 'customer_group_id', 'min_qty'], 'price_tiers_item_group_qty_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
