<?php

use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsModeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('legacy_event_id')->nullable()->unique()->constrained('events')->nullOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', array_column(GameStatusEnum::cases(), 'value'))
                ->default(GameStatusEnum::SCHEDULED->value);
            $table->unsignedSmallInteger('side_a_size')->default(5);
            $table->unsignedSmallInteger('side_b_size')->default(5);
            $table->enum('scoring_type', array_column(GameScoringTypeEnum::cases(), 'value'))
                ->default(GameScoringTypeEnum::BASKETBALL->value);
            $table->enum('statistics_mode', array_column(GameStatisticsModeEnum::cases(), 'value'))
                ->default(GameStatisticsModeEnum::FULL->value);
            $table->enum('statistics_status', array_column(GameStatisticsStatusEnum::cases(), 'value'))
                ->default(GameStatisticsStatusEnum::NOT_STARTED->value);
            $table->unsignedInteger('statistics_version')->default(1);
            $table->timestampTz('statistics_confirmed_at')->nullable();
            $table->foreignId('statistics_confirmed_by_actor_id')->nullable()
                ->constrained('actors')->restrictOnDelete();
            $table->timestampTz('scheduled_starts_at')->nullable();
            $table->timestampTz('scheduled_ends_at')->nullable();
            $table->timestampTz('actual_started_at')->nullable();
            $table->foreignId('actual_started_by_actor_id')->nullable()
                ->constrained('actors')->nullOnDelete();
            $table->timestampTz('actual_ended_at')->nullable();
            $table->foreignId('actual_ended_by_actor_id')->nullable()
                ->constrained('actors')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by_actor_id')->nullable()
                ->constrained('actors')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_actor_id')->nullable()
                ->constrained('actors')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('winner_game_side_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'status']);
            $table->index(['event_id', 'scheduled_starts_at']);
        });

        Schema::table('game_sides', function (Blueprint $table): void {
            $table->foreignId('game_id')->nullable()->constrained('games')->cascadeOnDelete();
            $table->index(['game_id', 'slot']);
        });

        foreach (['game_roster_entries', 'game_player_statistics'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('game_id')->nullable()->constrained('games')->cascadeOnDelete();
                $table->index(['game_id', 'game_side_id']);
            });
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->foreign('winner_game_side_id')
                ->references('id')
                ->on('game_sides')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropForeign(['winner_game_side_id']);
        });

        foreach (['game_player_statistics', 'game_roster_entries'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['game_id', 'game_side_id']);
                $table->dropConstrainedForeignId('game_id');
            });
        }

        Schema::table('game_sides', function (Blueprint $table): void {
            $table->dropIndex(['game_id', 'slot']);
            $table->dropConstrainedForeignId('game_id');
        });

        Schema::dropIfExists('games');
    }
};
