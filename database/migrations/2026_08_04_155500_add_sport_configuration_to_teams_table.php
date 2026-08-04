<?php

use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_sport_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->enum('sport_type', array_column(TeamSportTypeEnum::cases(), 'value'));
            $table->timestamps();

            $table->unique(['team_id', 'sport_type']);
            $table->index(['sport_type', 'team_id']);
        });

        DB::table('teams')->orderBy('id')->each(function ($team): void {
            DB::table('team_sport_profiles')->insert([
                'team_id' => $team->id,
                'sport_type' => TeamSportTypeEnum::BASKETBALL->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_sport_profiles');
    }
};
