<?php

use App\Modules\Tournament\Domain\Enums\TournamentEnrollmentPolicyEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->enum('enrollment_policy', array_column(TournamentEnrollmentPolicyEnum::cases(), 'value'))
                ->default(TournamentEnrollmentPolicyEnum::FIXED_POOL->value);
            $table->unsignedSmallInteger('round_robin_legs')->default(1);
            $table->timestampTz('recruitment_closed_at')->nullable();
            $table->foreignId('recruitment_closed_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();
            $table->timestampTz('tournament_closed_at')->nullable();
            $table->foreignId('tournament_closed_by_actor_id')->nullable()->constrained('actors')->nullOnDelete();

            $table->index(['enrollment_policy', 'recruitment_closed_at'], 'tournaments_enrollment_open_idx');
            $table->index('tournament_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropIndex('tournaments_enrollment_open_idx');
            $table->dropIndex(['tournament_closed_at']);
            $table->dropConstrainedForeignId('recruitment_closed_by_actor_id');
            $table->dropConstrainedForeignId('tournament_closed_by_actor_id');
            $table->dropColumn([
                'enrollment_policy',
                'round_robin_legs',
                'recruitment_closed_at',
                'tournament_closed_at',
            ]);
        });
    }
};
