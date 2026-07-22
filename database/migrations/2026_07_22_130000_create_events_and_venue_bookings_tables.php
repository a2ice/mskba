<?php

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('organizer_actor_id')->constrained('actors')->restrictOnDelete();
            $table->string('title');
            $table->string('alias');
            $table->enum('type', array_column(EventTypeEnum::cases(), 'value'));
            $table->enum('status', array_column(EventStatusEnum::cases(), 'value'))->default(EventStatusEnum::DRAFT->value);
            $table->enum('visibility', array_column(EventVisibilityEnum::cases(), 'value'))->default(EventVisibilityEnum::PUBLIC->value);
            $table->text('description')->nullable();
            $table->text('result_description')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('max_participants')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_actor_id')->nullable()->constrained('actors')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index(['venue_id', 'starts_at']);
        });

        Schema::create('event_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', array_column(EventParticipantRoleEnum::cases(), 'value'))->default(EventParticipantRoleEnum::PARTICIPANT->value);
            $table->enum('status', array_column(EventParticipantStatusEnum::cases(), 'value'))->default(EventParticipantStatusEnum::CONFIRMED->value);
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'status']);
        });

        Schema::create('venue_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->foreignId('created_by_actor_id')->constrained('actors')->restrictOnDelete();
            $table->enum('status', array_column(VenueBookingStatusEnum::cases(), 'value'));
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestamps();

            $table->index(['venue_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_bookings');
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('events');
    }
};
