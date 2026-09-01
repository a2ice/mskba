<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_rental_coordinations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organizer_actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('organizer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('venue_booking_id')->nullable()->unique()->constrained('venue_bookings')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open');
            $table->string('visibility', 16)->default('public');
            $table->string('participants_visibility', 16)->default('participants');
            $table->string('scope', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'status', 'starts_at']);
        });

        Schema::create('venue_rental_coordination_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coordination_id')->constrained('venue_rental_coordinations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['coordination_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_rental_coordination_participants');
        Schema::dropIfExists('venue_rental_coordinations');
    }
};
