<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->string('mode', 24);
            $table->string('original_name');
            $table->string('file_path');
            $table->string('sheet')->nullable();
            $table->string('media_source', 16)->default('none');
            $table->string('media_path')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);

            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('failure_message')->nullable();

            // Technical pipeline fields (D-033).
            $table->string('staging_path')->nullable();
            $table->string('categories_staging_path')->nullable();
            $table->unsignedInteger('chunk_size')->default(200);
            $table->unsignedInteger('chunks_total')->default(0);
            $table->unsignedInteger('next_chunk')->default(0);
            $table->unsignedInteger('chunks_done')->default(0);
            $table->json('chunk_offsets')->nullable();
            $table->json('analysis')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->dateTime('last_activity_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
