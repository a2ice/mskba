<?php

use App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_duplicates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('duplicate_venue_id')->constrained('venues')->cascadeOnDelete();
            $table->enum('matched_by', array_column(VenueDuplicateMatchTypeEnum::cases(), 'value'));
            $table->enum('status', array_column(VenueDuplicateStatusEnum::cases(), 'value'))
                ->default(VenueDuplicateStatusEnum::PENDING->value);
            $table->unsignedSmallInteger('score')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'duplicate_venue_id']);
            $table->index(['status', 'matched_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_duplicates');
    }
};
