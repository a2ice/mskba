<?php

use App\Modules\Event\Domain\Enums\GamePeriodStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->enum('timing_mode', array_column(GameTimingModeEnum::cases(), 'value'))
                ->default(GameTimingModeEnum::WHOLE_GAME->value)
                ->after('format');
        });
        DB::table('games')->update(['periods_count' => null]);

        Schema::create('game_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->enum('status', array_column(GamePeriodStatusEnum::cases(), 'value'))
                ->default(GamePeriodStatusEnum::SCHEDULED->value);
            $table->timestampTz('actual_started_at')->nullable();
            $table->foreignId('started_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->timestampTz('actual_ended_at')->nullable();
            $table->foreignId('ended_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->unsignedSmallInteger('side_a_score')->nullable();
            $table->unsignedSmallInteger('side_b_score')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'number']);
            $table->index(['game_id', 'status']);
        });

        Schema::table('game_actions', function (Blueprint $table): void {
            $table->foreignId('game_period_id')->nullable()->after('game_id')
                ->constrained('game_periods')->nullOnDelete();
            $table->index(['game_period_id', 'sequence']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE games ADD CONSTRAINT games_period_configuration_check CHECK ((timing_mode = 'whole_game' AND periods_count IS NULL) OR (timing_mode = 'periods' AND periods_count IN (2, 4)))");
        }
    }

    public function down(): void
    {
        Schema::table('game_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('game_period_id');
        });
        Schema::dropIfExists('game_periods');
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('timing_mode'));
    }
};
