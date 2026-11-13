<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 22.5: the promo code of a cart (and whether the customer typed it themselves, PM-23), the promo snapshot of an
 * order (code id with a null-on-delete FK, the code text, discount, the manual discount of the order editor, the
 * attributed promo link) and the share of the discount per order line (PM-18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->string('promo_code', 40)->nullable();
            $table->boolean('promo_applied_manually')->default(false);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            $table->string('promo_code', 40)->nullable();
            $table->decimal('promo_discount', 12, 2)->default(0);
            $table->decimal('manual_discount', 12, 2)->default(0);
            $table->foreignId('promo_link_id')->nullable()->constrained('promo_links')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('promo_discount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('promo_discount');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['promo_code_id']);
            $table->dropForeign(['promo_link_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['promo_code_id', 'promo_code', 'promo_discount', 'manual_discount', 'promo_link_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn(['promo_code', 'promo_applied_manually']);
        });
    }
};
