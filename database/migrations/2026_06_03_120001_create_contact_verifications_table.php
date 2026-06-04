<?php

use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->enum('channel', array_column(ContactVerificationChannelEnum::cases(), 'value'));
            $table->enum('status', array_column(ContactVerificationStatusEnum::cases(), 'value'))
                ->default(ContactVerificationStatusEnum::PENDING->value);
            $table->string('code_hash')->nullable();
            $table->string('sent_to');
            $table->unsignedSmallInteger('attempts_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_verifications');
    }
};
