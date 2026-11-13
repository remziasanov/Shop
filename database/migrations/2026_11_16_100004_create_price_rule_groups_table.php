<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-04: customer groups a price rule is bound to. A rule without rows is a rule for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rule_groups', function (Blueprint $table): void {
            $table->foreignId('price_rule_id')->constrained('price_rules')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();

            $table->primary(['price_rule_id', 'customer_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rule_groups');
    }
};
