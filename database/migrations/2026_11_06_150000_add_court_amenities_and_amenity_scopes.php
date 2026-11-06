<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table): void {
            $table->string('scope', 16)->default('venue')->index();
        });

        Schema::create('venue_court_amenities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_court_id')->constrained('venue_courts')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('amenities')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['venue_court_id', 'amenity_id']);
        });

        // Backfill physical court type only for previously unclassified zones.
        DB::table('venue_courts as c')->join('venues as v', 'v.id', '=', 'c.venue_id')
            ->whereNull('c.environment')
            ->update(['environment' => DB::raw("CASE WHEN v.type = 'street_court' THEN 'outdoor' ELSE 'indoor' END")]);

        $courtAliases = [
            'stands', 'scoreboard', 'equipment', 'team-benches', 'ventilation',
            'heating', 'lighting', 'fence', 'hoop-nets', 'benches', 'canopy',
            'winter-operation', 'snow-removal', 'round-the-clock-access',
        ];
        DB::table('amenities')->whereIn('alias', $courtAliases)->update(['scope' => 'court']);
        // Shared showers and changing rooms can be used by outdoor courts
        // within the same school or sports complex.
        DB::table('amenities')->where('scope', 'venue')
            ->update(['applies_to' => 'all']);

        // One-time data migration, not a runtime fallback. Move court-scoped
        // links to the actual court(s) of the appropriate environment.
        $links = DB::table('venue_amenities as va')
            ->join('amenities as a', 'a.id', '=', 'va.amenity_id')
            ->where('a.scope', 'court')->whereNull('va.deleted_at')
            ->get(['va.id', 'va.venue_id', 'va.amenity_id', 'a.applies_to']);

        foreach ($links as $link) {
            $courts = DB::table('venue_courts')->where('venue_id', $link->venue_id)
                ->whereNull('deleted_at')
                ->when($link->applies_to !== 'all', fn ($query) =>
                    $query->where('environment', $link->applies_to))
                ->pluck('id');

            foreach ($courts as $courtId) {
                DB::table('venue_court_amenities')->insertOrIgnore([
                    'venue_court_id' => $courtId,
                    'amenity_id' => $link->amenity_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            // Remove the invalid venue-level reference once copied.
            // Retain the soft-deleted row for audit / rollback.
            if ($courts->isNotEmpty()) {
                DB::table('venue_amenities')->where('id', $link->id)
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_court_amenities');
        Schema::table('amenities', fn (Blueprint $table) => $table->dropColumn('scope'));
    }
};
