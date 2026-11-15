<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ 18.7, CX-07: after the cleanup removes an expired customer export, its link must still answer 410. The tombstone
 * keeps the uuid, the owner and the end of the period for a few days. It holds no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_tombstones');
    }
};
