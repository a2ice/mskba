<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sides', function (Blueprint $table): void {
            $table->dropUnique(['event_id', 'slot']);
            $table->dropUnique(['event_id', 'team_id']);
            $table->unique(['game_id', 'slot']);
            $table->unique(['game_id', 'team_id']);
        });

        Schema::table('game_roster_entries', function (Blueprint $table): void {
            $table->dropUnique(['event_id', 'user_id']);
            $table->unique(['game_id', 'user_id']);
        });

        Schema::table('game_player_statistics', function (Blueprint $table): void {
            $table->dropUnique(['event_id', 'user_id']);
            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('game_player_statistics', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'user_id']);
            $table->unique(['event_id', 'user_id']);
        });

        Schema::table('game_roster_entries', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'user_id']);
            $table->unique(['event_id', 'user_id']);
        });

        Schema::table('game_sides', function (Blueprint $table): void {
            $table->dropUnique(['game_id', 'team_id']);
            $table->dropUnique(['game_id', 'slot']);
            $table->unique(['event_id', 'team_id']);
            $table->unique(['event_id', 'slot']);
        });
    }
};
