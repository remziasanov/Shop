<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CG-20…CG-24: applications for wholesale access. The personal fields are nullable because the anonymization of the
 * customer wipes them (CA-17) while the row, its status and the decision stay for the records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('status', 16)->default('new');

            $table->string('company_name')->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 9)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('comment')->nullable();

            $table->dateTime('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_user_agent', 500)->nullable();

            // CG-22: who decided, when, which group was granted, why it was rejected, the comment of a return to «new».
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->text('reject_reason')->nullable();
            $table->text('return_comment')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index('inn');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_applications');
    }
};
