<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('sales_mode', 16)->nullable();
            $table->integer('sort_order')->default(0);
            // Root categories have depth 1; the maximum is 4 (AD-30).
            $table->unsignedTinyInteger('depth')->default(1);
            // Materialized path of ids, e.g. "1/5/12/" (the category's own id is the last segment).
            $table->string('path', 255)->default('')->index();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
