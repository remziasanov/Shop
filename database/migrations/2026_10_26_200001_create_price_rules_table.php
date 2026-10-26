<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->string('action', 32);
            // Percent or rubles depending on the action; 4 decimals for percentages like 12.5.
            $table->decimal('value', 12, 4);
            $table->string('scope', 16);
            $table->boolean('include_subcategories')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('show_as_discount')->default(false);
            $table->boolean('stop_processing')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
