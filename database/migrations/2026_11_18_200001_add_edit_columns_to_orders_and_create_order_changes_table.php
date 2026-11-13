<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 24.4: optimistic lock of the order editor (`version`, the staff member of the last change for the «Заказ изменён
 * сотрудником …» message), the inquiry an order was created from, and the immutable change log of the editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('order_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->uuid('batch_uuid');
            $table->string('kind', 24);
            $table->string('field', 64)->nullable();
            // The order item is deleted by the edit itself, so this is a plain number without a foreign key.
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('item_label', 600)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');

            $table->index(['order_id', 'created_at']);
            $table->index('batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_changes');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['source_order_id']);
            $table->dropForeign(['updated_by_user_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['version', 'source_order_id', 'updated_by_user_id']);
        });
    }
};
