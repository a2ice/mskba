<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimApproved;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimRejected;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class ReviewVenueOwnershipClaimHandler
{
    public function __construct(
        private VenueMembershipAccess $memberships,
        private FeatureFlags $features,
    ) {}

    public function approve(VenueOwnershipClaim $claim, User $reviewer, ?string $reason = null): VenueOwnershipClaim
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $reviewer = $this->authorizedReviewer($reviewer);

        return DB::transaction(function () use ($claim, $reviewer, $reason): VenueOwnershipClaim {
            $venue = Venue::query()->lockForUpdate()->findOrFail($claim->venue_id);
            $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($claim->status === VenueOwnershipClaimStatusEnum::APPROVED) {
                return $claim;
            }

            if ($claim->status !== VenueOwnershipClaimStatusEnum::PENDING) {
                throw new VenueOwnershipClaimException('Решение по этой заявке уже принято.');
            }

            if ($reviewer->isSameIdentity($claim->applicant_user_id)) {
                throw new VenueOwnershipClaimException('Нельзя одобрить собственную заявку на владение.');
            }

            if ($this->memberships->hasActiveOwner($venue)) {
                throw new VenueOwnershipClaimException('У площадки уже есть активный владелец. Для смены владельца нужен отдельный процесс.');
            }

            $membership = $this->createOwnerMembership($venue, $claim, $reviewer);

            $claim->forceFill([
                'status' => VenueOwnershipClaimStatusEnum::APPROVED,
                'decision_reason' => $reason,
                'reviewer_user_id' => $reviewer->id,
                'owner_contract_membership_id' => $membership->id,
                'active_marker' => null,
                'decided_at' => now(),
            ])->save();

            DB::afterCommit(static fn () => event(new VenueOwnershipClaimApproved($claim->id)));

            return $claim->refresh();
        });
    }

    public function reject(VenueOwnershipClaim $claim, User $reviewer, string $reason): VenueOwnershipClaim
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $reviewer = $this->authorizedReviewer($reviewer);

        return DB::transaction(function () use ($claim, $reviewer, $reason): VenueOwnershipClaim {
            Venue::query()->lockForUpdate()->findOrFail($claim->venue_id);
            $claim = VenueOwnershipClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if ($claim->status !== VenueOwnershipClaimStatusEnum::PENDING) {
                throw new VenueOwnershipClaimException('Решение по этой заявке уже принято.');
            }

            $claim->forceFill([
                'status' => VenueOwnershipClaimStatusEnum::REJECTED,
                'decision_reason' => trim($reason),
                'reviewer_user_id' => $reviewer->id,
                'active_marker' => null,
                'decided_at' => now(),
            ])->save();

            DB::afterCommit(static fn () => event(new VenueOwnershipClaimRejected($claim->id)));

            return $claim->refresh();
        });
    }

    private function authorizedReviewer(User $reviewer): User
    {
        $reviewer = $reviewer->canonical();

        if (! $reviewer->isConfirmed() || ! $reviewer->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
            throw new VenueOwnershipClaimException('Рассматривать заявки на владение может только superadmin.');
        }

        return $reviewer;
    }

    private function createOwnerMembership(
        Venue $venue,
        VenueOwnershipClaim $claim,
        User $reviewer,
    ): ContractMembership {
        $accessLevel = VenueMembershipAccessLevelEnum::OWNER;
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'name' => "Владение площадкой: {$venue->name}",
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now(),
            'assigned_by' => $reviewer->id,
            'assigned_at' => now(),
            'assigner' => UserParticipationRoleAssignerEnum::USER,
            'comment' => "Одобрение заявки на владение #{$claim->id}",
        ]);

        $membership = $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $claim->applicant_user_id,
            'access_level' => $accessLevel->value,
        ]);

        $contract->permissions()->createMany(array_map(
            static fn ($permission): array => ['permission' => $permission->value],
            $accessLevel->defaultPermissions(),
        ));

        return $membership;
    }
}
