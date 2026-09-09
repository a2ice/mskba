<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        $primaryCourts = DB::table('venue_courts')
            ->where('is_primary', true)
            ->whereNull('deleted_at')
            ->pluck('id', 'venue_id');

        foreach ($primaryCourts as $venueId => $courtId) {
            DB::table('venue_booking_quotes')
                ->where('venue_id', $venueId)
                ->whereNull('venue_court_id')
                ->update(['venue_court_id' => $courtId]);
            DB::table('venue_bookings')
                ->where('venue_id', $venueId)
                ->whereNull('venue_court_id')
                ->update(['venue_court_id' => $courtId]);
            DB::table('events')
                ->where('venue_id', $venueId)
                ->whereNull('venue_court_id')
                ->update(['venue_court_id' => $courtId]);
        }

        DB::table('events')
            ->whereNotNull('booking_id')
            ->orderBy('id')
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $courtId = DB::table('venue_bookings')->where('id', $event->booking_id)->value('venue_court_id');
                    if ($courtId !== null) {
                        DB::table('events')->where('id', $event->id)->update(['venue_court_id' => $courtId]);
                    }
                }
            });
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
