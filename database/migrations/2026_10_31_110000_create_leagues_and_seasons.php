<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->string('name', 150);
            $table->string('alias', 180)->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'name']);
        });

        Schema::create('league_seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['league_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('league_team', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['league_id', 'team_id']);
        });

        Schema::create('league_season_team', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_season_id')->constrained('league_seasons')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['league_season_id', 'team_id']);
        });

        Schema::table('tournaments', function (Blueprint $table): void {
            $table->foreignId('league_id')->nullable()->constrained('leagues')->restrictOnDelete();
            $table->foreignId('league_season_id')->nullable()->constrained('league_seasons')->restrictOnDelete();
            $table->index(['league_id', 'league_season_id']);
        });

        Schema::table('games', function (Blueprint $table): void {
            // Direct league games are optional; tournament games can resolve
            // league context through TournamentMatch -> Tournament instead.
            $table->foreignId('league_id')->nullable()->constrained('leagues')->restrictOnDelete();
            $table->foreignId('league_season_id')->nullable()->constrained('league_seasons')->restrictOnDelete();
            $table->index(['league_id', 'league_season_id']);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropIndex(['league_id', 'league_season_id']);
            $table->dropConstrainedForeignId('league_season_id');
            $table->dropConstrainedForeignId('league_id');
        });
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropIndex(['league_id', 'league_season_id']);
            $table->dropConstrainedForeignId('league_season_id');
            $table->dropConstrainedForeignId('league_id');
        });
        Schema::dropIfExists('league_season_team');
        Schema::dropIfExists('league_team');
        Schema::dropIfExists('league_seasons');
        Schema::dropIfExists('leagues');
    }
};
