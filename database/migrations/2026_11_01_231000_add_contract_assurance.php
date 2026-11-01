<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->string('legal_nature', 32)->default('technical');
            $table->string('verification_requirement', 24)->default('none');
        });

        Schema::create('contract_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('source_type', 48)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_verifications');
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn(['legal_nature', 'verification_requirement']);
        });
    }
};
