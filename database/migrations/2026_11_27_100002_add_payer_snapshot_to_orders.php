<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CX-10, CX-11: the payer of an invoice is printed as it was when the order was placed, not as the customer's company
 * requisites are today. `payer_snapshot` is JSON: `{"company": null | {name, inn, kpp, ogrn, legal_address}}`; NULL
 * means no snapshot (orders of the earlier stages, guest orders): the printout falls back to the current requisites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('payer_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payer_snapshot');
        });
    }
};
