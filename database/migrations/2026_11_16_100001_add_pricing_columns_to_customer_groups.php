<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-01: the group discount from the retail price (percent, 0–99.99) and the option to show the retail price struck
 * through next to the group price. Defaults keep the behaviour of every existing group unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->decimal('default_discount_percent', 5, 2)->default(0);
            $table->boolean('show_retail_price')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->dropColumn(['default_discount_percent', 'show_retail_price']);
        });
    }
};
