<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('hoops_count')->nullable()->after('supports_halves');
            $table->string('surface_type', 32)->nullable()->after('hoops_count');
            $table->boolean('allows_whole')->default(true)->after('surface_type');
            $table->boolean('allows_halves')->default(false)->after('allows_whole');
        });

        $hoopsByVenue = Schema::hasTable('venue_characteristics')
            ? DB::table('venue_characteristics')->pluck('hoops_count', 'venue_id')
            : collect();
        $policiesByVenue = Schema::hasTable('venue_booking_policies')
            ? DB::table('venue_booking_policies')
                ->where('active_marker', true)
                ->select(['venue_id', 'allows_whole', 'allows_halves'])
                ->get()
                ->keyBy('venue_id')
            : collect();

        DB::table('venue_courts')
            ->select(['id', 'venue_id', 'is_primary', 'supports_halves'])
            ->orderBy('id')
            ->chunkById(500, function ($courts) use ($hoopsByVenue, $policiesByVenue): void {
                foreach ($courts as $court) {
                    $parentHoops = (int) ($hoopsByVenue[(int) $court->venue_id] ?? 0);
                    $hoopsCount = (bool) $court->is_primary && in_array($parentHoops, [1, 2], true)
                        ? $parentHoops
                        : ((bool) $court->supports_halves ? 2 : 1);
                    $supportsHalves = $hoopsCount >= 2;
                    $policy = $policiesByVenue->get((int) $court->venue_id);
                    $allowsWhole = $policy === null ? true : (bool) $policy->allows_whole;
                    $allowsHalves = $supportsHalves && $policy !== null && (bool) $policy->allows_halves;

                    DB::table('venue_courts')->where('id', $court->id)->update([
                        'hoops_count' => $hoopsCount,
                        'supports_halves' => $supportsHalves,
                        'allows_whole' => $allowsWhole,
                        'allows_halves' => $allowsHalves,
                    ]);
                }
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_courts ADD CONSTRAINT venue_courts_hoops_count_check CHECK (hoops_count IS NULL OR hoops_count IN (1, 2))');
            DB::statement('ALTER TABLE venue_courts ADD CONSTRAINT venue_courts_halves_capability_check CHECK (allows_halves = false OR supports_halves = true)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE venue_courts DROP CONSTRAINT IF EXISTS venue_courts_halves_capability_check');
            DB::statement('ALTER TABLE venue_courts DROP CONSTRAINT IF EXISTS venue_courts_hoops_count_check');
        }

        Schema::table('venue_courts', function (Blueprint $table): void {
            $table->dropColumn(['hoops_count', 'surface_type', 'allows_whole', 'allows_halves']);
        });
    }
};
