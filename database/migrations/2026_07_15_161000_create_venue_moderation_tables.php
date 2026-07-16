<?php

use App\Modules\Venue\Domain\Enums\VenueModerationMessageDirectionEnum;
use App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_moderation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table
                ->foreignId('submitted_by_actor_id')
                ->nullable()
                ->constrained('actors')
                ->nullOnDelete();
            $table
                ->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table
                ->enum('status', array_column(VenueModerationRequestStatusEnum::cases(), 'value'))
                ->default(VenueModerationRequestStatusEnum::PENDING->value);
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'status']);
        });

        Schema::create('venue_moderation_messages', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('venue_moderation_request_id')
                ->constrained('venue_moderation_requests')
                ->cascadeOnDelete();
            $table
                ->enum('direction', array_column(VenueModerationMessageDirectionEnum::cases(), 'value'));
            $table
                ->foreignId('author_actor_id')
                ->nullable()
                ->constrained('actors')
                ->nullOnDelete();
            $table
                ->foreignId('author_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_moderation_messages');
        Schema::dropIfExists('venue_moderation_requests');
    }
};
