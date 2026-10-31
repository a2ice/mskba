<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('league_seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues')->restrictOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug', 180);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['league_id', 'slug']);
            $table->index(['league_id', 'status']);
        });

        // Both references are optional: a tournament can be independent,
        // attached directly to a league, or attached to a season of that league.
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->foreignId('league_id')->nullable()->constrained('leagues')->restrictOnDelete();
            $table->foreignId('league_season_id')->nullable()->constrained('league_seasons')->restrictOnDelete();
            $table->index(['league_id', 'league_season_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE league_seasons ADD CONSTRAINT league_seasons_period_check CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on)');
            DB::statement("ALTER TABLE leagues ADD CONSTRAINT leagues_status_check CHECK (status IN ('active', 'archived'))");
            DB::statement("ALTER TABLE league_seasons ADD CONSTRAINT league_seasons_status_check CHECK (status IN ('planned', 'active', 'completed', 'archived'))");
        }
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropIndex(['league_id', 'league_season_id']);
            $table->dropConstrainedForeignId('league_season_id');
            $table->dropConstrainedForeignId('league_id');
        });

        Schema::dropIfExists('league_seasons');
        Schema::dropIfExists('leagues');
    }
};
