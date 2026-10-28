<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CG-05: categories selected for a group (read as «except» or «only» by `category_access_mode`; a selection includes
 * the subcategories, they are not stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_group_categories', function (Blueprint $table): void {
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->primary(['customer_group_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_categories');
    }
};
