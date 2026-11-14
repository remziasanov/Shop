<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 32.1 (TS-03, TS-07, TS-08):
 *  - `users.telegram_personal_notify` (default off);
 *  - `order_status_history.source`: existing rows with a user are `admin`, the rest `system` (in version 1.0 a null
 *    user meant "system or customer", which cannot be told apart afterwards);
 *  - `admin_audit_log.source`: existing rows are `admin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('telegram_personal_notify')->default(false);
        });

        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->string('source', 20)->default('system');
        });

        DB::table('order_status_history')->whereNotNull('user_id')->update(['source' => 'admin']);

        Schema::table('admin_audit_log', function (Blueprint $table): void {
            $table->string('source', 20)->default('admin');
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_log', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('telegram_personal_notify');
        });
    }
};
