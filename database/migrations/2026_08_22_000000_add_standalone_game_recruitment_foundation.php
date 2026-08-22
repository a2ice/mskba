<?php

use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->enum('recruitment_mode', array_column(GameRecruitmentModeEnum::cases(), 'value'))
                ->nullable()
                ->after('status');
            $table->timestampTz('sides_confirmed_at')->nullable()->after('recruitment_mode');
            $table->foreignId('sides_confirmed_by_actor_id')
                ->nullable()
                ->after('sides_confirmed_at')
                ->constrained('actors')
                ->nullOnDelete();
            $table->index(['recruitment_mode', 'sides_confirmed_at']);
        });

        Schema::create('game_admissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->enum('candidate_type', array_column(GameAdmissionCandidateTypeEnum::cases(), 'value'));
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('direction', array_column(GameAdmissionDirectionEnum::cases(), 'value'));
            $table->enum('status', array_column(GameAdmissionStatusEnum::cases(), 'value'))
                ->default(GameAdmissionStatusEnum::PENDING->value);
            $table->foreignId('requested_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('responded_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->text('response_comment')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'candidate_type', 'status']);
            $table->index(['game_id', 'team_id', 'status']);
            $table->index(['game_id', 'user_id', 'status']);
        });

        $confirmedGameIds = DB::table('game_sides')
            ->select('game_id')
            ->whereNotNull('game_id')
            ->groupBy('game_id')
            ->havingRaw('COUNT(*) = 2')
            ->pluck('game_id');

        if ($confirmedGameIds->isNotEmpty()) {
            DB::table('games')
                ->whereIn('id', $confirmedGameIds)
                ->whereNull('sides_confirmed_at')
                ->update(['sides_confirmed_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_admissions');

        Schema::table('games', function (Blueprint $table): void {
            $table->dropIndex(['recruitment_mode', 'sides_confirmed_at']);
            $table->dropConstrainedForeignId('sides_confirmed_by_actor_id');
            $table->dropColumn(['recruitment_mode', 'sides_confirmed_at']);
        });
    }
};
