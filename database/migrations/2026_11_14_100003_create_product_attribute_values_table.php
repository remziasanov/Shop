<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-03, FL-12, 20.4: values of a product. list: one row per value (`attribute_value_id`); number: one row with
 * `value_number`; boolean: one row with `value_bool`. "One value per pair" for number/boolean is enforced by
 * AttributeService (a unique index cannot do it: NULL never collides). The value FK is RESTRICT: a value used by
 * products cannot be deleted (FL-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->nullable()->constrained('attribute_values')->restrictOnDelete();
            $table->decimal('value_number', 14, 4)->nullable();
            $table->boolean('value_bool')->nullable();

            // The same list value cannot be attached twice (NULLs of number/boolean rows do not collide).
            $table->unique(['product_id', 'attribute_id', 'attribute_value_id'], 'pav_product_attribute_value_unique');
            // FL-12: filters, facets and number ranges.
            $table->index(['attribute_id', 'attribute_value_id', 'product_id'], 'pav_attribute_value_product_index');
            $table->index(['attribute_id', 'value_number', 'product_id'], 'pav_attribute_number_product_index');
            $table->index(['product_id', 'attribute_id'], 'pav_product_attribute_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
