<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 23.4 (MR-05, MR-12): `related_order_id` links the order and the reservation made by one checkout (set on both rows,
 * null on delete of the other one); `reserved_via_cart` marks a reservation made from the cart (admin filter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('related_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->boolean('reserved_via_cart')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['related_order_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['related_order_id', 'reserved_via_cart']);
        });
    }
};
