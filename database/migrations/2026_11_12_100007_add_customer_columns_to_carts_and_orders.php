<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CA-14, CA-15, CA-26, 17.5: a cart and a request can belong to a customer. `carts.customer_id` is unique (several
 * NULLs are fine in MySQL and SQLite): it protects the cart merge from a race. The group of a request is a snapshot
 * (id and name), so a later group change or deletion does not touch old requests; it has no foreign key.
 * Existing requests stay guest requests (NULL).
 *
 * As in the roles migration, SQLite (tests only) cannot add a foreign key to an existing table: it is created on
 * MySQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable()->unique();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('customer_group_id')->nullable();
            $table->string('customer_group_name')->nullable();

            $table->index(['customer_id', 'created_at']);
            $table->index('customer_email');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('carts', function (Blueprint $table): void {
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            });

            Schema::table('orders', function (Blueprint $table): void {
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropForeign(['customer_id']);
            });

            Schema::table('carts', function (Blueprint $table): void {
                $table->dropForeign(['customer_id']);
            });
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropIndex(['customer_email']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['customer_id', 'customer_group_id', 'customer_group_name']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique(['customer_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropColumn('customer_id');
        });
    }
};
