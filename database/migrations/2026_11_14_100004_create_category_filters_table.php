<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-05, FL-06, 20.4: the set and order of configurable filters of a category.
 *
 * `option_type_id` deliberately has NO foreign key yet: the option tables (`option_types`, VR-19) arrive in stage 11,
 * whose migration adds the constraint. Until then only `source = attribute` rows are written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('source', 16)->default('attribute');
            $table->foreignId('attribute_id')->nullable()->constrained('attributes')->cascadeOnDelete();
            $table->unsignedBigInteger('option_type_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_expanded')->default(false);

            $table->index(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_filters');
    }
};
