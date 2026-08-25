<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_booking_attendance_rounds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('venue_booking_id')->constrained('venue_bookings')->cascadeOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('status', 24)->default('open')->index();
            $table->boolean('active_marker')->nullable();
            $table->string('responses_visibility', 24)->default('participants');
            $table->timestampTz('deadline_at');
            $table->unsignedInteger('minimum_yes_responses');
            $table->unsignedInteger('yes_count')->default(0);
            $table->unsignedInteger('no_count')->default(0);
            $table->unsignedInteger('maybe_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->timestampTz('threshold_reached_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_reason', 32)->nullable();
            $table->timestampsTz();

            $table->unique(['venue_booking_id', 'active_marker'], 'venue_booking_attendance_active_unique');
            $table->index(['status', 'deadline_at']);
        });

        Schema::create('venue_booking_attendance_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('round_id')->constrained('venue_booking_attendance_rounds')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('response', 16)->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['round_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_booking_attendance_responses');
        Schema::dropIfExists('venue_booking_attendance_rounds');
    }
};
