<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name', 500);
            $table->string('slug')->unique();
            $table->string('brand')->nullable()->index();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->json('attributes')->nullable();

            $table->decimal('base_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('fixed_price', 12, 2)->nullable();
            $table->boolean('price_on_request')->default(false);
            $table->decimal('final_price', 12, 2)->default(0)->index();
            $table->decimal('display_old_price', 12, 2)->nullable();
            $table->timestamp('price_calculated_at')->nullable();

            // null = stock is not tracked for this product.
            $table->integer('stock_qty')->nullable();
            $table->integer('reserved_qty')->default(0);
            $table->string('unit', 20)->default('шт');
            $table->string('sales_mode', 16)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_new')->default(false)->index();
            $table->string('search_text', 1000)->default('');
            $table->integer('sort_order')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['category_id', 'is_active', 'sort_order'], 'products_category_active_sort_index');
            $table->index(['is_active', 'final_price'], 'products_active_price_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
