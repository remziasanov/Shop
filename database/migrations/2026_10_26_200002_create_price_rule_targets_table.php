<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rule_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_rule_id')->constrained('price_rules')->cascadeOnDelete();
            $table->string('target_type', 16);
            // Category or product id (no FK: targets of deleted products/categories are simply ignored).
            $table->unsignedBigInteger('target_id')->nullable();
            // Brand name or flag code.
            $table->string('target_value')->nullable();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rule_targets');
    }
};
