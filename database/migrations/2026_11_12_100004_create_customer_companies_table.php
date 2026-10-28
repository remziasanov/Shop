<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CA-10: company requisites; one set per customer (several companies are «Позже», CA-30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('inn', 12)->index();
            $table->string('kpp', 9)->nullable();
            $table->string('ogrn', 15)->nullable();
            $table->text('legal_address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_companies');
    }
};
