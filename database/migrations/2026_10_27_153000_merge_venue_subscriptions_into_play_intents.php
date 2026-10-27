<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('venue_subscriptions')) return;

        DB::table('venue_subscriptions')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $venue = DB::table('venues')->where('id', $row->venue_id)->first(['id', 'name']);
                if ($venue === null) continue;

                $existing = DB::table('play_intents')->where('user_id', $row->user_id)
                    ->where('venue_id', $row->venue_id)
                    ->whereIn('status', ['active', 'paused'])->orderBy('id')->first();

                $channels = $row->delivery_channels ?: json_encode(['in_app']);
                if ($existing !== null) {
                    if ($row->status === 'active') {
                        // Active venue news must remain active even when the
                        // older game search was paused. Preserve its filters
                        // but do not silently resume matched-game alerts.
                        DB::table('play_intents')->where('id', $existing->id)->update([
                            'status' => 'active',
                            'notify_matches' => $existing->status === 'paused' ? false : $existing->notify_matches,
                            'notify_venue_news' => true,
                            'venue_delivery_channels' => $channels,
                            'updated_at' => now(),
                        ]);
                    }
                    continue;
                }

                DB::table('play_intents')->insert([
                    'user_id' => $row->user_id,
                    'venue_id' => $row->venue_id,
                    'name' => mb_substr('Подписка на «'.$venue->name.'»', 0, 80),
                    'include_similar_venues' => false,
                    'cadence' => 'flexible',
                    'weekdays' => json_encode([]), 'dates' => json_encode([]),
                    'windows' => json_encode([]), 'formats' => json_encode([]),
                    'amenity_preferences' => json_encode([]),
                    'access_mode' => 'any',
                    'venue_kind' => 'any',
                    'notify_matches' => true, 'notify_offers' => false,
                    'notify_venue_news' => $row->status === 'active',
                    'game_delivery_channels' => json_encode(['in_app']),
                    'venue_delivery_channels' => $channels,
                    'show_on_venue' => false,
                    'status' => $row->status,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }
        });
        // Keep legacy rows untouched for audit/rollback. All new reads and
        // writes use only play_intents from now on.
    }

    public function down(): void
    {
        // No destructive rollback: migrated subscriptions are user data.
    }
};
