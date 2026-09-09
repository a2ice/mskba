<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_fingerprints', function (Blueprint $table): void {
            $table->index('last_seen_at', 'user_fingerprints_last_seen_at_index');
            $table->index('created_at', 'user_fingerprints_created_at_index');
        });

        Schema::table('game_live_view_sessions', function (Blueprint $table): void {
            $table->index('last_seen_at', 'game_live_view_sessions_last_seen_at_index');
            $table->index('created_at', 'game_live_view_sessions_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('game_live_view_sessions', function (Blueprint $table): void {
            $table->dropIndex('game_live_view_sessions_created_at_index');
            $table->dropIndex('game_live_view_sessions_last_seen_at_index');
        });

        Schema::table('user_fingerprints', function (Blueprint $table): void {
            $table->dropIndex('user_fingerprints_created_at_index');
            $table->dropIndex('user_fingerprints_last_seen_at_index');
        });
    }
};
