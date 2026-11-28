<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_invitation_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['game_id', 'team_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('request_threads')->where('type', 'game_invitation')->exists()) {
            throw new \RuntimeException('Game invitation conversation history must be preserved.');
        }
        Schema::dropIfExists('game_invitation_threads');
    }
};
