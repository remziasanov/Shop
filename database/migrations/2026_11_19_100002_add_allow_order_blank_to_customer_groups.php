<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CX-10: the flag «Может скачивать бланк/счёт» of a customer group. On for the wholesale group «Опт» (code `opt`),
 * off for «Гость», «Розница» and every other group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->boolean('allow_order_blank')->default(false);
        });

        DB::table('customer_groups')->where('code', 'opt')->update(['allow_order_blank' => true]);
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->dropColumn('allow_order_blank');
        });
    }
};
