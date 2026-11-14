<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('planned_booking_scope', 16)->nullable();
            $table->boolean('booking_deferred')->default(false);
            $table->uuid('creation_key')->nullable()->unique();
        });

        Schema::create('event_venue_interests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('venue_court_id')->nullable()->constrained('venue_courts')->nullOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('scope', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 24)->default('open');
            $table->timestamps();

            $table->unique(['event_id', 'revision']);
            $table->index(['venue_id', 'status', 'starts_at'], 'event_interest_venue_status_start');
            $table->index(['venue_court_id', 'starts_at']);
        });

        Schema::create('event_venue_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('changed_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('previous_venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('next_venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('previous_court_id')->nullable()->constrained('venue_courts')->nullOnDelete();
            $table->foreignId('next_court_id')->nullable()->constrained('venue_courts')->nullOnDelete();
            $table->string('previous_scope', 16)->nullable();
            $table->string('next_scope', 16)->nullable();
            $table->timestamp('previous_starts_at');
            $table->timestamp('previous_ends_at');
            $table->timestamp('next_starts_at');
            $table->timestamp('next_ends_at');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_venue_revisions');
        Schema::dropIfExists('event_venue_interests');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropUnique(['creation_key']);
            $table->dropColumn(['planned_booking_scope', 'booking_deferred', 'creation_key']);
        });
    }
};
