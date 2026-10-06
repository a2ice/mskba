<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reward_id')->constrained('rewards')->restrictOnDelete();
            $table->foreignId('reward_version_id')->constrained('reward_versions')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('mechanism_code', 96);
            $table->string('wallet_operation_type', 64);
            $table->string('business_fact_type', 80);
            $table->string('business_fact_key', 191);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('RUB');
            $table->string('status', 32)->default('pending');
            $table->foreignId('wallet_operation_id')->nullable()->unique()->constrained('wallet_operations')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['reward_id', 'business_fact_type', 'business_fact_key', 'recipient_user_id'],
                'reward_grants_fact_recipient_unique',
            );
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_grants');
    }
};
