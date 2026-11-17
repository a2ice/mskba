<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A venue is rewarded only after its first successful moderation, not on form submit.
        $promotionId = DB::table('promotions')
            ->where('code', 'reward_venue_first_approval')
            ->value('id');

        if ($promotionId === null) {
            return;
        }

        DB::table('promotion_placements')->insertOrIgnore([
            'promotion_id' => $promotionId,
            'placement_code' => 'venue_create',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $promotionId = DB::table('promotions')
            ->where('code', 'reward_venue_first_approval')
            ->value('id');

        if ($promotionId !== null) {
            DB::table('promotion_placements')
                ->where('promotion_id', $promotionId)
                ->where('placement_code', 'venue_create')
                ->delete();
        }
    }
};
