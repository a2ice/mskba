<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['game_sides', 'game_roster_entries', 'game_player_statistics'] as $table) {
            if (DB::table($table)->whereNull('game_id')->exists()) {
                throw new RuntimeException("Cleanup остановлен: {$table} содержит строки без game_id.");
            }
        }

        $childIds = DB::table('events')->whereNotNull('parent_event_id')->pluck('id');
        if ($childIds->isNotEmpty()) {
            foreach (['venue_bookings', 'event_participants', 'telegram_event_publications'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->whereIn('event_id', $childIds)->exists()) {
                    throw new RuntimeException("Cleanup остановлен: дочерние Event имеют данные в {$table}.");
                }
            }
            if (Schema::hasTable('media') && DB::table('media')
                ->where('mediable_type', 'event')
                ->whereIn('mediable_id', $childIds)
                ->exists()) {
                throw new RuntimeException('Cleanup остановлен: дочерние Event имеют media-вложения.');
            }
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->dropUnique(['legacy_event_id']);
            $table->dropConstrainedForeignId('legacy_event_id');
        });

        if ($childIds->isNotEmpty()) {
            DB::table('events')->whereIn('id', $childIds)->delete();
        }

        Schema::drop('game_details');

        Schema::table('game_player_statistics', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_id');
        });
        Schema::table('game_roster_entries', function (Blueprint $table): void {
            $table->dropIndex('game_roster_event_side_captain_idx');
            $table->dropIndex('game_roster_event_side_lineup_idx');
            $table->dropConstrainedForeignId('event_id');
            $table->index(['game_id', 'game_side_id', 'lineup_role'], 'game_roster_game_side_lineup_idx');
            $table->index(['game_id', 'game_side_id', 'is_captain'], 'game_roster_game_side_captain_idx');
        });
        Schema::table('game_sides', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_id');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(['parent_event_id', 'starts_at']);
            $table->dropConstrainedForeignId('parent_event_id');
            $table->dropConstrainedForeignId('actual_ended_by_actor_id');
            $table->dropColumn('actual_ended_at');
            $table->dropConstrainedForeignId('actual_started_by_actor_id');
            $table->dropColumn('actual_started_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy Event-as-Game структура не восстанавливается автоматически.');
    }
};
