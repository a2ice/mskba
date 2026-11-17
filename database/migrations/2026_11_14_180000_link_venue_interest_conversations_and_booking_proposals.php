<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_booking_conversations', function (Blueprint $table): void {
            // Pre-requests can now open a conversation before a booking exists.
            $table->foreignId('venue_booking_id')->nullable()->change();
            $table->foreignId('event_venue_interest_id')->nullable()->unique()
                ->constrained('event_venue_interests')->nullOnDelete();
        });

        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->foreignId('event_venue_interest_id')->nullable()
                ->constrained('event_venue_interests')->nullOnDelete();
            $table->string('interest_booking_initiator', 16)->nullable();
        });

        Schema::create('event_venue_booking_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_venue_interest_id')->constrained('event_venue_interests')->cascadeOnDelete();
            $table->foreignId('initiated_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->unique()->constrained('venue_bookings')->nullOnDelete();
            $table->unsignedInteger('event_confirmation_version');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('quoted_amount_minor')->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['event_venue_interest_id', 'status'], 'event_interest_proposals_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_venue_booking_proposals');
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_venue_interest_id');
            $table->dropColumn('interest_booking_initiator');
        });
        Schema::table('venue_booking_conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_venue_interest_id');
            $table->foreignId('venue_booking_id')->nullable(false)->change();
        });
    }
};
