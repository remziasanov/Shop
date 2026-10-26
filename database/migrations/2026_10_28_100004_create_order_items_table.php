<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // Snapshot of the product at the time of the request.
            $table->string('sku', 100);
            $table->string('name', 500);
            $table->string('unit', 20);
            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedInteger('qty');
            $table->decimal('total', 12, 2)->nullable();
            // Quantity actually deducted from stock_qty (orders) or held in reserved_qty (reservations), D-046.
            $table->unsignedInteger('stock_applied_qty')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
