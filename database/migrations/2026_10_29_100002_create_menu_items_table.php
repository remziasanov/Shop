<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            // header | footer
            $table->string('location', 16)->index();
            $table->string('label');
            // category | page | url
            $table->string('link_type', 16);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('url', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['location', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
