<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('generated_title', 180);
            $table->string('custom_title', 80)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('format', 12)->nullable();
            $table->string('scope_key', 100)->nullable()->index();
            $table->foreignId('anchor_venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->json('common_venue_ids')->nullable();
            $table->json('common_time')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('admin_assigned_at')->nullable();
            $table->string('admin_source', 24)->nullable();
            $table->timestampsTz();
        });

        Schema::create('play_opportunity_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('play_opportunities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('play_intent_ids')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();
            $table->unique(['opportunity_id', 'user_id']);
        });

        Schema::create('play_group_elections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('play_opportunities')->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->json('electorate_user_ids');
            $table->unsignedInteger('electorate_count');
            $table->unsignedInteger('votes_required');
            $table->foreignId('previous_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('earliest_close_at');
            $table->timestampTz('closes_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['opportunity_id', 'status']);
        });

        Schema::create('play_group_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_id')->constrained('play_group_elections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();
            $table->unique(['election_id', 'user_id']);
        });

        Schema::create('play_group_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('election_id')->constrained('play_group_elections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('play_group_candidates')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['election_id', 'user_id']);
            $table->index(['election_id', 'candidate_id']);
        });

        Schema::create('play_group_venue_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('play_opportunities')->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('unsuitable')->default(false);
            $table->string('comment', 300)->nullable();
            $table->json('windows')->nullable();
            $table->timestampsTz();
            $table->unique(['opportunity_id', 'venue_id', 'user_id'], 'play_group_venue_user_unique');
        });

        Schema::create('play_group_assemblies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('play_opportunities')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('coordination_session_id')->nullable()->constrained('coordination_sessions')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();
            $table->index(['opportunity_id','status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_group_assemblies');
        Schema::dropIfExists('play_group_venue_preferences');
        Schema::dropIfExists('play_group_votes');
        Schema::dropIfExists('play_group_candidates');
        Schema::dropIfExists('play_group_elections');
        Schema::dropIfExists('play_opportunity_members');
        Schema::dropIfExists('play_opportunities');
    }
};
