<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-06, GP-08: the denormalized price of a customer group (steps 2 and 3 of 21.2) for a product (variant_key 0) or a
 * variant; a row exists only when it differs from the retail price. index(customer_group_id, final_price) serves
 * sorting and filtering by the viewer's price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_group_prices_final', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('variant_key')->default(0);
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->decimal('final_price', 12, 2);
            $table->decimal('display_old_price', 12, 2)->nullable();
            $table->dateTime('computed_at')->nullable();

            $table->unique(['product_id', 'variant_key', 'customer_group_id'], 'group_prices_item_group_unique');
            $table->index(['customer_group_id', 'final_price'], 'group_prices_group_price_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_prices_final');
    }
};
