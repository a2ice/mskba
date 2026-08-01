<?php

use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatisticsStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_details', function (Blueprint $table): void {
            $table->enum('scoring_type', array_column(GameScoringTypeEnum::cases(), 'value'))
                ->default(GameScoringTypeEnum::STREETBALL->value)
                ->after('side_b_size');
        });

        DB::table('game_details')
            ->where('statistics_status', GameStatisticsStatusEnum::CONFIRMED->value)
            ->orderBy('event_id')
            ->each(function (object $detail): void {
                $scores = DB::table('game_player_statistics')
                    ->where('event_id', $detail->event_id)
                    ->selectRaw('game_side_id, SUM(close_made + mid_made + free_throw_made + (three_made * 2)) AS score')
                    ->groupBy('game_side_id')
                    ->pluck('score', 'game_side_id');

                foreach ($scores as $sideId => $score) {
                    DB::table('game_sides')->where('id', $sideId)->update(['score' => (int) $score]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('game_details', fn (Blueprint $table) => $table->dropColumn('scoring_type'));
    }
};
