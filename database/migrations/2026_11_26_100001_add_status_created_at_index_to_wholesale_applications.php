<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 25.5: the list of applications is filtered by status and ordered by date, so the index is `(status, created_at)`;
 * the single-column `status` index is a prefix of it and is dropped (TD-12, D-171).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_applications', function (Blueprint $table): void {
            $table->index(['status', 'created_at']);
        });

        Schema::table('wholesale_applications', function (Blueprint $table): void {
            $table->dropIndex(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_applications', function (Blueprint $table): void {
            $table->index('status');
        });

        Schema::table('wholesale_applications', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
