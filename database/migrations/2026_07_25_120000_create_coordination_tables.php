<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordination_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organizer_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('status', 32)->index();
            $table->string('context_type', 32)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['context_type', 'context_id']);
        });

        Schema::create('coordination_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('coordination_sessions')->cascadeOnDelete();
            $table->string('question', 500);
            $table->string('subject_type', 32);
            $table->string('selection_mode', 16);
            $table->string('results_visibility', 32);
            $table->string('status', 16)->index();
            $table->boolean('allows_suggestions')->default(false);
            $table->timestampTz('closes_at')->index();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('coordination_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('coordination_polls')->cascadeOnDelete();
            $table->string('label', 255);
            $table->jsonb('value');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['poll_id', 'is_active', 'sort_order']);
        });

        Schema::create('coordination_ballots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('coordination_polls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['poll_id', 'user_id']);
        });

        Schema::create('coordination_ballot_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ballot_id')->constrained('coordination_ballots')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('coordination_poll_options')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['ballot_id', 'option_id']);
        });

        Schema::create('coordination_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->unique()->constrained('coordination_sessions')->cascadeOnDelete();
            $table->foreignId('poll_id')->constrained('coordination_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('coordination_poll_options')->restrictOnDelete();
            $table->foreignId('decided_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->timestampTz('decided_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_decisions');
        Schema::dropIfExists('coordination_ballot_selections');
        Schema::dropIfExists('coordination_ballots');
        Schema::dropIfExists('coordination_poll_options');
        Schema::dropIfExists('coordination_polls');
        Schema::dropIfExists('coordination_sessions');
    }
};
