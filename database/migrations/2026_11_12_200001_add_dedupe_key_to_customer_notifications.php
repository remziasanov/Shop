<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CA-18: a key that keeps a recurring event from creating the same notification twice (for instance «the reservation
 * expires» for one reservation and one term). NULL for notifications that need no such guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->string('dedupe_key', 120)->nullable();

            $table->index(['customer_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'dedupe_key']);
        });

        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->dropColumn('dedupe_key');
        });
    }
};
