<?php

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('name');
            $table->string('alias')->unique();
            $table->text('description')->nullable();
            $table->enum('status', array_column(SportsSectionStatusEnum::cases(), 'value'))->default(SportsSectionStatusEnum::DRAFT->value);
            $table->enum('training_mode', array_column(TrainingModeEnum::cases(), 'value'));
            $table->enum('game_format', [GameFormatEnum::BASKETBALL_5X5->value, GameFormatEnum::STREETBALL_3X3->value, GameFormatEnum::STREETBALL_1X1->value])->default(GameFormatEnum::BASKETBALL_5X5->value);
            $table->foreignId('primary_venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->foreignId('primary_venue_court_id')->nullable()->constrained('venue_courts')->nullOnDelete();
            $table->enum('pricing_type', array_column(SectionPricingTypeEnum::cases(), 'value'))->default(SectionPricingTypeEnum::FREE->value);
            $table->unsignedBigInteger('single_session_price_minor')->nullable();
            $table->char('currency', 3)->default('RUB');
            $table->enum('contact_source', array_column(SectionContactSourceEnum::cases(), 'value'))->default(SectionContactSourceEnum::HEAD_COACH->value);
            $table->text('contact_notes')->nullable();
            $table->foreignId('head_coach_membership_id')->nullable()->constrained('contract_memberships')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'game_format']);
        });

        Schema::create('section_trainee_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sports_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('status', array_column(TraineeMembershipStatusEnum::cases(), 'value'))->default(TraineeMembershipStatusEnum::ACTIVE->value);
            $table->text('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['sports_section_id', 'user_id']);
            $table->index(['sports_section_id', 'status']);
        });

        Schema::create('section_pricing_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sports_section_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('RUB');
            $table->unsignedInteger('sessions_count')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['sports_section_id', 'is_active']);
        });

        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sports_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', array_column(TrainingSessionStatusEnum::cases(), 'value'))->default(TrainingSessionStatusEnum::PLANNED->value);
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('price_override_minor')->nullable();
            $table->unsignedBigInteger('confirmed_price_minor')->nullable();
            $table->char('confirmed_currency', 3)->nullable();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->foreignId('venue_court_id')->nullable()->constrained('venue_courts')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->unique()->constrained('events')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['sports_section_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('training_session_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_trainee_membership_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('attendance_status', 32)->nullable();
            $table->timestamps();

            $table->unique(['training_session_id', 'user_id']);
        });

        Schema::create('training_session_coaches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_membership_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['training_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_session_coaches');
        Schema::dropIfExists('training_session_participants');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('section_pricing_plans');
        Schema::dropIfExists('section_trainee_memberships');
        Schema::dropIfExists('sports_sections');
    }
};
