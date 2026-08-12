<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_live_view_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_fingerprint_id')->nullable()->constrained('user_fingerprints')->nullOnDelete();
            $table->char('viewer_key_hash', 64);
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->timestamps();

            $table->index(['game_id', 'last_seen_at']);
            $table->index(['game_id', 'user_id']);
            $table->index(['game_id', 'viewer_key_hash', 'last_seen_at'], 'game_live_viewer_session_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_live_view_sessions');
    }
};
