<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('game_side_id')->nullable()->constrained('game_sides')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->string('type', 64);
            $table->unsignedTinyInteger('points')->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->unique(['game_id', 'sequence']);
            $table->index(['game_id', 'occurred_at']);
            $table->index(['game_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_actions');
    }
};
