<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            // Paths, e.g. "/product/old-slug". 300 chars fit a 255-char slug plus the prefix.
            $table->string('from_path', 300)->unique();
            $table->string('to_path', 300);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
