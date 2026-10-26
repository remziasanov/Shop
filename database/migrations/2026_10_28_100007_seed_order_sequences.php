<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One counter row per request type must exist before the first request is numbered: creating it lazily with
 * INSERT IGNORE next to a locking read deadlocks on MySQL (D-053).
 */
return new class extends Migration
{
    private const TYPES = ['order', 'reservation', 'inquiry'];

    public function up(): void
    {
        foreach (self::TYPES as $type) {
            DB::table('order_sequences')->insertOrIgnore(['type' => $type, 'last_number' => 0]);
        }
    }

    public function down(): void
    {
        DB::table('order_sequences')->whereIn('type', self::TYPES)->delete();
    }
};
