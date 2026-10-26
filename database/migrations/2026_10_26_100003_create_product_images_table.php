<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path_large');
            $table->string('path_medium');
            $table->string('path_small');
            $table->string('alt')->nullable();
            $table->integer('sort_order')->default(0);
            // Dimensions of the large variant.
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            // sha1 of the source URL or file name: lets re-imports skip downloads.
            $table->string('source_hash', 40)->nullable()->index();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
