<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('column')->nullable();
            $table->string('level', 8);
            $table->string('message', 1000);
            $table->text('value')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['import_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
    }
};
