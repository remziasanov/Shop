<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 32.1 (TM-14): `orders.channel` — web / pwa / telegram (enum OrderChannel, stored as varchar). Existing rows are `web`
 * through the column default; the index serves the channel filter of the order list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('channel', 20)->default('web');
            $table->index(['channel', 'created_at'], 'orders_channel_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_channel_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
