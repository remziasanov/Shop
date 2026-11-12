<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-01, 20.4: the dictionary of characteristics. `slug` is varchar(190) so that the unique index stays within the
 * 767-byte index limit of MySQL 5.7 with utf8mb4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 190)->unique();
            $table->string('type', 16)->default('list');
            $table->string('unit', 20)->nullable();
            $table->boolean('is_filterable')->default(false);
            $table->boolean('index_single_value')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_filterable', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
    }
};
