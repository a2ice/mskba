<?php

use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Keep the existing enum CHECK constraints and column defaults.
            DB::statement('ALTER TABLE games ALTER COLUMN side_a_size DROP NOT NULL, ALTER COLUMN side_b_size DROP NOT NULL, ALTER COLUMN scoring_type DROP NOT NULL, ALTER COLUMN timing_mode DROP NOT NULL');
        } else {
            Schema::table('games', function (Blueprint $table): void {
                $table->unsignedSmallInteger('side_a_size')->nullable()->default(5)->change();
                $table->unsignedSmallInteger('side_b_size')->nullable()->default(5)->change();
                $table->enum('scoring_type', array_column(GameScoringTypeEnum::cases(), 'value'))->nullable()->default(GameScoringTypeEnum::BASKETBALL->value)->change();
                $table->enum('timing_mode', array_column(GameTimingModeEnum::cases(), 'value'))->nullable()->default(GameTimingModeEnum::WHOLE_GAME->value)->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('games')->where(function ($query): void {
            $query->whereNull('side_a_size')->orWhereNull('side_b_size')
                ->orWhereNull('scoring_type')->orWhereNull('timing_mode');
        })->exists()) {
            throw new \RuntimeException('Configure unconfigured games before rolling back their schema.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE games ALTER COLUMN side_a_size SET NOT NULL, ALTER COLUMN side_b_size SET NOT NULL, ALTER COLUMN scoring_type SET NOT NULL, ALTER COLUMN timing_mode SET NOT NULL');
        } else {
            Schema::table('games', function (Blueprint $table): void {
                $table->unsignedSmallInteger('side_a_size')->default(5)->change();
                $table->unsignedSmallInteger('side_b_size')->default(5)->change();
                $table->enum('scoring_type', array_column(GameScoringTypeEnum::cases(), 'value'))->default(GameScoringTypeEnum::BASKETBALL->value)->change();
                $table->enum('timing_mode', array_column(GameTimingModeEnum::cases(), 'value'))->default(GameTimingModeEnum::WHOLE_GAME->value)->change();
            });
        }
    }
};
