<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_booking_quotes', function (Blueprint $table): void {
            $table->foreignId('venue_court_id')->nullable()->after('venue_id')->constrained('venue_courts')->restrictOnDelete();
            $table->index(['venue_court_id', 'starts_at'], 'venue_booking_quotes_court_start');
        });

        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->foreignId('venue_court_id')->nullable()->after('venue_id')->constrained('venue_courts')->restrictOnDelete();
            $table->index(['venue_court_id', 'status', 'starts_at'], 'venue_bookings_court_lookup');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('venue_court_id')->nullable()->after('venue_id')->constrained('venue_courts')->restrictOnDelete();
            $table->index(['venue_court_id', 'status', 'starts_at'], 'events_court_lookup');
        });

        // Historical rows predate VenueCourt and therefore do not contain reliable
        // physical-hall identity. Keep them NULL deliberately: application conflict
        // resolution treats NULL as venue-wide, preventing false availability after
        // the migration. New quote/booking/event flows always persist a concrete court.
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex('events_court_lookup');
            $table->dropConstrainedForeignId('venue_court_id');
        });
        Schema::table('venue_bookings', function (Blueprint $table): void {
            $table->dropIndex('venue_bookings_court_lookup');
            $table->dropConstrainedForeignId('venue_court_id');
        });
        Schema::table('venue_booking_quotes', function (Blueprint $table): void {
            $table->dropIndex('venue_booking_quotes_court_start');
            $table->dropConstrainedForeignId('venue_court_id');
        });
    }
};
