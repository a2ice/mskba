<?php

use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->enum('body_type', array_column(PlayerBodyTypeEnum::cases(), 'value'))->nullable();
        });

        Schema::create('player_profile_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('position', array_column(PlayerPositionEnum::cases(), 'value'));
            $table->timestamps();
            $table->unique(['player_profile_id', 'position']);
        });

        DB::table('player_profiles')
            ->whereNotNull('position')
            ->orderBy('id')
            ->get(['id', 'position'])
            ->each(function (object $profile): void {
                $position = $profile->position === 'forward'
                    ? PlayerPositionEnum::SMALL_FORWARD->value
                    : $profile->position;

                DB::table('player_profile_positions')->insert([
                    'player_profile_id' => $profile->id,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropColumn('position');
        });

        Schema::create('player_self_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->unique()->cascadeOnDelete();
            $table->unsignedTinyInteger('stamina')->nullable();
            $table->unsignedTinyInteger('speed')->nullable();
            $table->unsignedTinyInteger('ball_handling')->nullable();
            $table->unsignedTinyInteger('passing')->nullable();
            $table->unsignedTinyInteger('shooting')->nullable();
            $table->unsignedTinyInteger('defense')->nullable();
            $table->unsignedTinyInteger('rebounding')->nullable();
            $table->unsignedTinyInteger('basketball_iq')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_self_assessments');

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->enum('position', [
                ...array_column(PlayerPositionEnum::cases(), 'value'),
                'forward',
            ])->nullable();
        });

        DB::table('player_profile_positions')
            ->orderBy('id')
            ->get()
            ->groupBy('player_profile_id')
            ->each(function ($positions, int $profileId): void {
                DB::table('player_profiles')
                    ->where('id', $profileId)
                    ->update(['position' => $positions->first()->position]);
            });

        Schema::dropIfExists('player_profile_positions');

        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropColumn('body_type');
        });
    }
};
