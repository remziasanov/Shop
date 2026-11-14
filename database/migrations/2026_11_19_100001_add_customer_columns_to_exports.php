<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CX-06, CX-07 (TZ 18.5): one journal for the exports of staff and of customers. A customer export carries its owner
 * (`customer_id`), a public identifier for the download link (`uuid`), the end of the download period (`expires_at`)
 * and the size of the file. `user_id` was already nullable (customer exports have no staff owner).
 *
 * As in the carts/orders migration, SQLite (tests only) cannot add a foreign key to an existing table: it is created
 * on MySQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exports', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('uuid', 36)->nullable()->unique();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->index(['customer_id', 'created_at']);
            $table->index('expires_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('exports', function (Blueprint $table): void {
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('exports', function (Blueprint $table): void {
                $table->dropForeign(['customer_id']);
            });
        }

        Schema::table('exports', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropIndex(['expires_at']);
            $table->dropUnique(['uuid']);
            $table->dropColumn(['customer_id', 'uuid', 'expires_at', 'file_size']);
        });
    }
};
