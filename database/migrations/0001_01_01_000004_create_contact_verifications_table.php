<?php

use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->enum('purpose', array_column(ContactVerificationPurposeEnum::cases(), 'value'));
            $table->enum('status', array_column(ContactVerificationStatusEnum::cases(), 'value'))
                ->default(ContactVerificationStatusEnum::PENDING->value);
            $table->string('value')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_verifications');
    }
};
