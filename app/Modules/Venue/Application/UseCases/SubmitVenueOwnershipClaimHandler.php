<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimSubmitted;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use Illuminate\Support\Facades\DB;

final readonly class SubmitVenueOwnershipClaimHandler
{
    public function __construct(
        private VenueMembershipAccess $memberships,
        private VenueUserRestrictionService $restrictions,
    ) {}

    public function handle(Venue $venue, User $applicant, string $evidence): VenueOwnershipClaim
    {
        $applicant = $applicant->canonical();

        if ($applicant->isBlocked() || $applicant->trashed()) {
            throw new VenueOwnershipClaimException('Подать заявку может только активный пользователь.');
        }

        if (! $applicant->isConfirmed() && ! $applicant->hasVerifiedPrimaryContact()) {
            throw new VenueOwnershipClaimException('Для подачи заявки подтвердите аккаунт или основной контакт.');
        }

        $claim = DB::transaction(function () use ($venue, $applicant, $evidence): VenueOwnershipClaim {
            $lockedVenue = Venue::query()->lockForUpdate()->findOrFail($venue->id);

            $restriction = $this->restrictions->active(
                $lockedVenue,
                $applicant,
                VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM,
            );
            if ($restriction !== null) {
                throw new VenueOwnershipClaimException(
                    'Для вашего аккаунта заблокирована подача заявок на управление этой площадкой. Причина: '.$restriction->reason,
                );
            }

            if ($this->memberships->hasActiveOwner($lockedVenue)) {
                throw new VenueOwnershipClaimException('У площадки уже есть подтверждённый представитель. Смена владельца выполняется отдельным процессом.');
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
