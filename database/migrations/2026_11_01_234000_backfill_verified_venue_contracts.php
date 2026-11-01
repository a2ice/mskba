<?php

use App\Modules\Contract\Domain\Models\ContractVerification;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve the reviewed ownership provenance of existing venues.
        // Never invent a new owner or silently validate an unapproved claim.
        VenueOwnership::query()->with(['contractMembership.contract', 'sourceClaim'])
            ->whereNotNull('contract_membership_id')
            ->orderBy('id')
            ->chunkById(100, function ($ownerships): void {
                foreach ($ownerships as $ownership) {
                    $contract = $ownership->contractMembership?->contract;
                    if ($contract === null) {
                        continue;
                    }
                    $contract->update([
                        'legal_nature' => 'evidence_backed',
                        'verification_requirement' => 'required',
                    ]);

                    $approved = $ownership->status === VenueOwnershipStatusEnum::ACTIVE;
                    ContractVerification::query()->updateOrCreate(
                        ['contract_id' => $contract->id],
                        [
                            'status' => $approved ? 'verified' : 'rejected',
                            'source_type' => 'venue_ownership_claim',
                            'source_id' => $ownership->source_claim_id,
                            'reviewer_user_id' => $ownership->sourceClaim?->reviewer_user_id
                                ?? $ownership->status_changed_by_user_id,
                            'verified_at' => $approved ? ($ownership->approved_at ?? now()) : null,
                            'decision_reason' => $ownership->status_reason,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        // Verification is a historical security decision, not throwaway data.
        // Never erase reviewed evidence on rollback.
    }
};
