<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VR-12, VR-15, VR-22: the variant of an order line with its snapshot (label, barcode; the SKU snapshot already
 * exists) and the variant a price history row belongs to. The FKs are SET NULL: the snapshot outlives the variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('variant_label', 255)->nullable();
            $table->string('barcode', 32)->nullable();
        });

        Schema::table('price_history', function (Blueprint $table): void {
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_history', function (Blueprint $table): void {
            $table->dropForeign(['variant_id']);
        });

        Schema::table('price_history', function (Blueprint $table): void {
            $table->dropColumn('variant_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['variant_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['variant_id', 'variant_label', 'barcode']);
        });
    }
};
