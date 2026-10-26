<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite (tests only) cannot add a foreign key to an existing table, so the constraint is created
     * on MySQL only; on SQLite `price_history.import_id` stays a plain indexed column.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('price_history', function (Blueprint $table): void {
            $table->foreign('import_id')->references('id')->on('imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('price_history', function (Blueprint $table): void {
            $table->dropForeign(['import_id']);
        });
    }
};
