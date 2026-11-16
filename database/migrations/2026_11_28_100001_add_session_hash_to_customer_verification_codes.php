<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-04: an e-mail code can be entered only from the browser session that requested it. `session_hash` is the SHA-256
 * of the session token; null means «not bound» (staff-issued codes, rows from before the change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_verification_codes', function (Blueprint $table): void {
            $table->string('session_hash', 64)->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('customer_verification_codes', function (Blueprint $table): void {
            $table->dropColumn('session_hash');
        });
    }
};
