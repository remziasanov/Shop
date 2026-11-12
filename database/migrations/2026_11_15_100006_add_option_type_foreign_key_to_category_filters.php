<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FL-05, VR-19: `category_filters.option_type_id` (created in stage 10 without a constraint) now references
 * `option_types`; deleting an option removes it from the filter sets.
 *
 * SQLite (tests only) cannot add a foreign key to an existing table, so the constraint exists on MySQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('category_filters', function (Blueprint $table): void {
            $table->foreign('option_type_id')->references('id')->on('option_types')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('category_filters', function (Blueprint $table): void {
            $table->dropForeign(['option_type_id']);
        });
    }
};
