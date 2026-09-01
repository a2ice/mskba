<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class SubmitVenueOwnershipClaimHandler
{
    public function __construct(
        private VenueMembershipAccess $memberships,
        private FeatureFlags $features,
    ) {}

    public function handle(Venue $venue, User $applicant, string $evidence): VenueOwnershipClaim
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $applicant = $applicant->canonical();

        if (! $applicant->isConfirmed() || $applicant->isBlocked() || $applicant->trashed()) {
            throw new VenueOwnershipClaimException('Подать заявку может только подтверждённый активный пользователь.');
        }

        $claim = DB::transaction(function () use ($venue, $applicant, $evidence): VenueOwnershipClaim {
            $lockedVenue = Venue::query()->lockForUpdate()->findOrFail($venue->id);

            if ($this->memberships->hasActiveOwner($lockedVenue)) {
                throw new VenueOwnershipClaimException('У площадки уже есть подтверждённый владелец. Смена владельца выполняется отдельным процессом.');
            }

            $hasPendingClaim = VenueOwnershipClaim::query()
                ->where('venue_id', $lockedVenue->id)
                ->whereIn('applicant_user_id', $applicant->identityIds())
                ->where('status', VenueOwnershipClaimStatusEnum::PENDING->value)
                ->exists();

            if ($hasPendingClaim) {
                throw new VenueOwnershipClaimException('Ваша заявка на эту площадку уже находится на рассмотрении.');
            }

            $claim = VenueOwnershipClaim::query()->create([
                'venue_id' => $lockedVenue->id,
                'applicant_user_id' => $applicant->id,
                'status' => VenueOwnershipClaimStatusEnum::PENDING,
                'evidence' => trim($evidence),
                'active_marker' => true,
                'submitted_at' => now(),
            ]);

            DB::afterCommit(static fn () => event(new VenueOwnershipClaimSubmitted($claim->id)));

            return $claim;
        });

        return $claim;
    }
}
