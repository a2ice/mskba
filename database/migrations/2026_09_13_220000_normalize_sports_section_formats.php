<?php

use App\Modules\SportsSection\Domain\Enums\SportsSectionFormatEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->dropIndex(['status', 'game_format']);
            $table->enum('training_mode_v2', array_column(TrainingModeEnum::cases(), 'value'))
                ->default(TrainingModeEnum::GROUP->value);
            $table->enum('game_format_v2', array_column(SportsSectionFormatEnum::cases(), 'value'))
                ->default(SportsSectionFormatEnum::BASKETBALL->value);
        });

        DB::table('sports_sections')
            ->where('training_mode', 'individual')
            ->update(['training_mode_v2' => TrainingModeEnum::INDIVIDUAL->value]);
        DB::table('sports_sections')
            ->whereIn('training_mode', ['small_group', 'team'])
            ->update(['training_mode_v2' => TrainingModeEnum::GROUP->value]);

        DB::table('sports_sections')
            ->where('game_format', 'basketball_5x5')
            ->update(['game_format_v2' => SportsSectionFormatEnum::BASKETBALL->value]);
        DB::table('sports_sections')
            ->whereIn('game_format', ['streetball_3x3', 'streetball_1x1'])
            ->update(['game_format_v2' => SportsSectionFormatEnum::STREETBALL->value]);
        DB::table('sports_sections')
            ->whereNotIn('game_format', ['basketball_5x5', 'streetball_3x3', 'streetball_1x1'])
            ->update(['game_format_v2' => SportsSectionFormatEnum::OTHER->value]);

        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->dropColumn(['training_mode', 'game_format']);
        });

        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->renameColumn('training_mode_v2', 'training_mode');
            $table->renameColumn('game_format_v2', 'game_format');
            $table->index(['status', 'game_format']);
        });
    }

    public function down(): void
    {
        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->dropIndex(['status', 'game_format']);
            $table->enum('training_mode_legacy', ['individual', 'small_group', 'team'])
                ->default('small_group');
            $table->enum('game_format_legacy', ['basketball_5x5', 'streetball_3x3', 'streetball_1x1'])
                ->default('basketball_5x5');
        });

        DB::table('sports_sections')
            ->where('training_mode', TrainingModeEnum::INDIVIDUAL->value)
            ->update(['training_mode_legacy' => 'individual']);
        DB::table('sports_sections')
            ->where('training_mode', TrainingModeEnum::GROUP->value)
            ->update(['training_mode_legacy' => 'small_group']);

        DB::table('sports_sections')
            ->where('game_format', SportsSectionFormatEnum::BASKETBALL->value)
            ->update(['game_format_legacy' => 'basketball_5x5']);
        DB::table('sports_sections')
            ->where('game_format', SportsSectionFormatEnum::STREETBALL->value)
            ->update(['game_format_legacy' => 'streetball_3x3']);
        DB::table('sports_sections')
            ->where('game_format', SportsSectionFormatEnum::OTHER->value)
            ->update(['game_format_legacy' => 'basketball_5x5']);

        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->dropColumn(['training_mode', 'game_format']);
        });

        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->renameColumn('training_mode_legacy', 'training_mode');
            $table->renameColumn('game_format_legacy', 'game_format');
            $table->index(['status', 'game_format']);
        });
    }
};
