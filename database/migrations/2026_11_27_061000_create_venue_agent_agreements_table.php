<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_agent_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('proposed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('proposed');
            $table->string('mode', 16);
            $table->unsignedBigInteger('value');
            $table->string('currency', 3);
            $table->json('proposed_permissions');
            $table->text('terms');
            $table->string('terms_hash', 64);
            $table->timestamp('proposed_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('closure_reason')->nullable();
            // The future verified/activated agreement will link the existing
            // membership contract and economic assignment, not duplicate them.
            $table->foreignId('contract_id')->nullable()->unique()->constrained('contracts')->restrictOnDelete();
            $table->foreignId('commission_agent_id')->nullable()->constrained('venue_commission_agents')->restrictOnDelete();
            $table->timestamps();
            $table->index(['venue_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_agent_agreements');
    }
};
