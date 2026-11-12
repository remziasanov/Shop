<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VR-01, VR-02, 19.3: the dictionary of options (Color, Size), their values and the axes of a product card.
 *
 * Deleting an option value that variants use is refused by the database too (the variant value FK is RESTRICT, see the
 * variants migration), the admin shows «используется в N товарах» (VR-26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            // color / button / select (OptionDisplayType).
            $table->string('display_type', 16)->default('select');
            $table->boolean('is_filterable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('option_type_id')->constrained('option_types')->cascadeOnDelete();
            $table->string('value', 150);
            $table->string('slug', 150);
            $table->string('hex', 7)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['option_type_id', 'slug']);
        });

        Schema::create('product_options', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('option_type_id')->constrained('option_types')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'option_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('option_values');
        Schema::dropIfExists('option_types');
    }
};
