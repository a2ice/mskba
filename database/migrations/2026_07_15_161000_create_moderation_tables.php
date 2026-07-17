<?php

use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_requests', function (Blueprint $table): void {
            $table->id();
            $table
                ->enum('type', array_column(ModerationTypeEnum::cases(), 'value'))
                ->default(ModerationTypeEnum::VENUE->value);
            $table->unsignedBigInteger('subject_id');
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
                ->enum('status', array_column(ModerationRequestStatusEnum::cases(), 'value'))
                ->default(ModerationRequestStatusEnum::PENDING->value);
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'subject_id', 'status']);
        });

        Schema::create('moderation_messages', function (Blueprint $table): void {
            $table->id();
            $table
                ->foreignId('moderation_request_id')
                ->constrained('moderation_requests')
                ->cascadeOnDelete();
            $table
                ->foreignId('sender_id')
                ->nullable()
                ->constrained('actors')
                ->nullOnDelete();
            $table
                ->foreignId('receiver_id')
                ->nullable()
                ->constrained('actors')
                ->nullOnDelete();
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_messages');
        Schema::dropIfExists('moderation_requests');
    }
};
