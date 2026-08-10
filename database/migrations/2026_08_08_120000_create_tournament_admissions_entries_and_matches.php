<?php

use App\Modules\Tournament\Domain\Enums\TournamentAdmissionCandidateTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionDirectionEnum;
use App\Modules\Tournament\Domain\Enums\TournamentAdmissionStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntryStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentRecruitmentModeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->enum('recruitment_mode', array_column(TournamentRecruitmentModeEnum::cases(), 'value'))
                ->default(TournamentRecruitmentModeEnum::PREFORMED_TEAMS->value)
                ->after('format');
        });

        Schema::create('tournament_admissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->enum('candidate_type', array_column(TournamentAdmissionCandidateTypeEnum::cases(), 'value'));
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('direction', array_column(TournamentAdmissionDirectionEnum::cases(), 'value'));
            $table->enum('status', array_column(TournamentAdmissionStatusEnum::cases(), 'value'))
                ->default(TournamentAdmissionStatusEnum::PENDING->value);
            $table->foreignId('requested_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->foreignId('responded_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['tournament_id', 'status']);
            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('tournament_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained('tournament_admissions')->nullOnDelete();
            $table->enum('source', array_column(TournamentEntrySourceEnum::cases(), 'value'));
            $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
            $table->string('name', 150);
            $table->enum('status', array_column(TournamentEntryStatusEnum::cases(), 'value'))
                ->default(TournamentEntryStatusEnum::ACTIVE->value);
            $table->unsignedSmallInteger('seed')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestamps();

            $table->unique('admission_id');
            $table->unique(['tournament_id', 'team_id']);
            $table->index(['tournament_id', 'status', 'position']);
        });

        Schema::create('tournament_entry_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_entry_id')->constrained('tournament_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_contract_membership_id')->nullable()->constrained('contract_memberships')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tournament_entry_id', 'user_id']);
            $table->index(['user_id', 'tournament_entry_id']);
        });

        Schema::create('tournament_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_a_id')->constrained('tournament_entries')->restrictOnDelete();
            $table->foreignId('entry_b_id')->constrained('tournament_entries')->restrictOnDelete();
            $table->foreignId('game_id')->nullable()->unique()->constrained('games')->restrictOnDelete();
            $table->unsignedSmallInteger('round')->nullable();
            $table->unsignedInteger('sequence');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tournament_id', 'sequence']);
            $table->index(['tournament_id', 'round', 'sequence']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tournament_admissions ADD CONSTRAINT tournament_admission_candidate_check CHECK ((candidate_type = 'team' AND team_id IS NOT NULL AND user_id IS NULL) OR (candidate_type = 'user' AND user_id IS NOT NULL AND team_id IS NULL))");
            DB::statement('ALTER TABLE tournament_matches ADD CONSTRAINT tournament_match_distinct_entries_check CHECK (entry_a_id <> entry_b_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_entry_members');
        Schema::dropIfExists('tournament_entries');
        Schema::dropIfExists('tournament_admissions');
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn('recruitment_mode'));
    }
};
