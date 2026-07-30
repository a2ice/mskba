<?php

use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsModeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignId('parent_event_id')
                ->nullable()
                ->after('id')
                ->constrained('events')
                ->cascadeOnDelete();
            $table->index(['parent_event_id', 'starts_at']);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('temporary_for_event_id')
                ->nullable()
                ->constrained('events')
                ->cascadeOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('name');
            $table->string('alias');
            $table->text('description')->nullable();
            $table->enum('status', array_column(TeamStatusEnum::cases(), 'value'))
                ->default(TeamStatusEnum::DRAFT->value);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('alias');
            $table->index(['status', 'name']);
            $table->index(['temporary_for_event_id', 'status']);
        });

        Schema::create('game_details', function (Blueprint $table): void {
            $table->foreignId('event_id')->primary()->constrained('events')->cascadeOnDelete();
            $table->unsignedSmallInteger('side_a_size')->default(5);
            $table->unsignedSmallInteger('side_b_size')->default(5);
            $table->enum('statistics_mode', array_column(GameStatisticsModeEnum::cases(), 'value'))
                ->default(GameStatisticsModeEnum::FULL->value);
            $table->enum('statistics_status', array_column(GameStatisticsStatusEnum::cases(), 'value'))
                ->default(GameStatisticsStatusEnum::NOT_STARTED->value);
            $table->unsignedInteger('statistics_version')->default(1);
            $table->timestampTz('statistics_confirmed_at')->nullable();
            $table->foreignId('statistics_confirmed_by_actor_id')
                ->nullable()
                ->constrained('actors')
                ->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('game_sides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('slot', 1);
            $table->string('display_name');
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'slot']);
            $table->unique(['event_id', 'team_id']);
        });

        Schema::create('game_roster_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('game_side_id')->constrained('game_sides')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_contract_membership_id')
                ->nullable()
                ->constrained('contract_memberships')
                ->nullOnDelete();
            $table->foreignId('source_event_participant_id')
                ->nullable()
                ->constrained('event_participants')
                ->nullOnDelete();
            $table->enum('status', array_column(GameRosterStatusEnum::cases(), 'value'))
                ->default(GameRosterStatusEnum::SELECTED->value);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['game_side_id', 'status']);
        });

        Schema::create('game_player_statistics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('game_side_id')->constrained('game_sides')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->unsignedSmallInteger('close_made')->default(0);
            $table->unsignedSmallInteger('close_attempted')->default(0);
            $table->unsignedSmallInteger('mid_made')->default(0);
            $table->unsignedSmallInteger('mid_attempted')->default(0);
            $table->unsignedSmallInteger('three_made')->default(0);
            $table->unsignedSmallInteger('three_attempted')->default(0);
            $table->unsignedSmallInteger('free_throw_made')->default(0);
            $table->unsignedSmallInteger('free_throw_attempted')->default(0);
            $table->unsignedSmallInteger('offensive_rebounds')->default(0);
            $table->unsignedSmallInteger('defensive_rebounds')->default(0);
            $table->unsignedSmallInteger('assists')->default(0);
            $table->unsignedSmallInteger('steals')->default(0);
            $table->unsignedSmallInteger('blocks')->default(0);
            $table->unsignedSmallInteger('turnovers')->default(0);
            $table->unsignedSmallInteger('fouls')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['game_side_id', 'user_id']);
        });

        Schema::create('player_objective_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('stamina', 4, 2)->nullable();
            $table->decimal('passing', 4, 2)->nullable();
            $table->decimal('close_range_shooting', 4, 2)->nullable();
            $table->decimal('mid_range_shooting', 4, 2)->nullable();
            $table->decimal('long_range_shooting', 4, 2)->nullable();
            $table->decimal('defense', 4, 2)->nullable();
            $table->decimal('rebounding', 4, 2)->nullable();
            $table->unsignedInteger('games_count')->default(0);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->unsignedSmallInteger('formula_version')->default(1);
            $table->timestampTz('calculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_objective_assessments');
        Schema::dropIfExists('game_player_statistics');
        Schema::dropIfExists('game_roster_entries');
        Schema::dropIfExists('game_sides');
        Schema::dropIfExists('game_details');
        Schema::dropIfExists('teams');

        Schema::table('events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_event_id');
        });
    }
};
