<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VR-03, VR-04, 19.3: variants of a product card and their option values.
 *
 * `sku` is unique inside this table; the uniqueness against `products.sku` is checked by SkuGuard (D-087).
 * `combo_hash` = sha1 of the sorted option value ids. Prices are NULL = inherit the card. The three option columns of
 * `product_variant_values` are FKs: the variant cascades, the type and value are RESTRICT (a used value is not deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('barcode', 32)->nullable();
            $table->char('combo_hash', 40);

            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('fixed_price', 12, 2)->nullable();
            $table->decimal('final_price', 12, 2)->default(0);
            $table->decimal('display_old_price', 12, 2)->nullable();
            $table->dateTime('price_calculated_at')->nullable();

            // null = stock is not tracked for this variant.
            $table->integer('stock_qty')->nullable();
            $table->integer('reserved_qty')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_id', 'combo_hash']);
            $table->index(['product_id', 'is_active', 'final_price'], 'product_variants_product_active_price_index');
            $table->index('barcode');
        });

        Schema::create('product_variant_values', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('option_type_id')->constrained('option_types')->restrictOnDelete();
            $table->foreignId('option_value_id')->constrained('option_values')->restrictOnDelete();

            $table->primary(['variant_id', 'option_type_id']);
            $table->index(['option_value_id', 'variant_id'], 'pvv_value_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
    }
};
