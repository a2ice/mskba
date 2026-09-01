<?php

use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_ownership_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', array_column(VenueOwnershipClaimStatusEnum::cases(), 'value'))
                ->default(VenueOwnershipClaimStatusEnum::PENDING->value);
            $table->text('evidence');
            $table->text('decision_reason')->nullable();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_contract_membership_id')->nullable()->constrained('contract_memberships')->nullOnDelete();
            $table->boolean('active_marker')->nullable()->default(true);
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['venue_id', 'applicant_user_id', 'active_marker'],
                'venue_ownership_claims_one_active_per_applicant',
            );
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_ownership_claims');
    }
};
