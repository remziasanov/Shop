<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VR-02, VR-07, VR-11: `products.has_variants` (the card works with variants), `products.final_price_max` (the
 * maximum variant price; `final_price` is the minimum), `product_images.option_value_id` (a photo of a colour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_variants')->default(false)->index();
            $table->decimal('final_price_max', 12, 2)->nullable();
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->foreignId('option_value_id')->nullable()->constrained('option_values')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropForeign(['option_value_id']);
        });

        Schema::table('product_images', function (Blueprint $table): void {
            $table->dropColumn('option_value_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['has_variants']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['has_variants', 'final_price_max']);
        });
    }
};
