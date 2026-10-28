<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * AG-01: categories.age_restriction and products.age_restriction (NULL = none / inherit, '16', '18').
 * The cached category tree keeps the column, so the stale copy is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('age_restriction', 2)->nullable();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('age_restriction', 2)->nullable();
            // Filter of the admin product list (AG-12).
            $table->index('age_restriction');
        });

        Cache::forget('catalog.tree');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['age_restriction']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('age_restriction');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('age_restriction');
        });

        Cache::forget('catalog.tree');
    }
};
