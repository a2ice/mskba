<?php

use App\Modules\SportsSection\Domain\Enums\SportsSectionJoinRequestStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->boolean('accepts_trainee_requests')->default(false);
            $table->boolean('is_recruiting')->default(false);
            $table->index(['status', 'accepts_trainee_requests']);
            $table->index(['status', 'is_recruiting']);
        });

        Schema::create('sports_section_join_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sports_section_id')->constrained('sports_sections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', array_column(SportsSectionJoinRequestStatusEnum::cases(), 'value'))
                ->default(SportsSectionJoinRequestStatusEnum::PENDING->value);
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['sports_section_id', 'status']);
            $table->index(['sports_section_id', 'user_id', 'status']);
        });

        // Repair the Team invariant for existing data: an active vacancy means
        // the team must accept applications globally as well.
        DB::table('teams')
            ->whereIn('id', DB::table('team_hiring_positions')
                ->select('team_id')
                ->where('status', 'active')
                ->whereColumn('spots_filled', '<', 'spots_total'))
            ->update(['accepts_join_requests' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_section_join_requests');

        Schema::table('sports_sections', function (Blueprint $table): void {
            $table->dropIndex(['status', 'accepts_trainee_requests']);
            $table->dropIndex(['status', 'is_recruiting']);
            $table->dropColumn(['accepts_trainee_requests', 'is_recruiting']);
        });
    }
};
