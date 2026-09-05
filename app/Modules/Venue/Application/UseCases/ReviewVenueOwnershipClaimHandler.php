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
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimApproved;
use App\Modules\Venue\Domain\Events\VenueOwnershipClaimRejected;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Infrastructure\Broadcasting\VenueOwnershipClaimUpdatedBroadcast;
use Illuminate\Support\Facades\DB;

final readonly class ReviewVenueOwnershipClaimHandler
{
    public function __construct(
        private VenueMembershipAccess $memberships,
    ) {}

    public function approve(VenueOwnershipClaim $claim, User $reviewer, ?string $reason = null): VenueOwnershipClaim
    {
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
                throw new VenueOwnershipClaimException('Нельзя одобрить собственную заявку на управление.');
            }

            if ($this->memberships->hasActiveOwner($venue)) {
                throw new VenueOwnershipClaimException('У площадки уже есть активный подтверждённый представитель.');
            }

            $membership = $this->createOwnerMembership($venue, $claim, $reviewer);
            $decidedAt = now();

            VenueOwnership::query()->create([
                'venue_id' => $venue->id,
                'owner_user_id' => $claim->applicant_user_id,
                'source_claim_id' => $claim->id,
                'contract_membership_id' => $membership->id,
                'status' => VenueOwnershipStatusEnum::ACTIVE,
                'status_reason' => filled($reason) ? trim((string) $reason) : null,
                'status_changed_by_user_id' => $reviewer->id,
                'status_changed_at' => $decidedAt,
                'approved_at' => $decidedAt,
                'active_marker' => true,
            ]);

            $claim->forceFill([
                'status' => VenueOwnershipClaimStatusEnum::APPROVED,
                'decision_reason' => filled($reason) ? trim((string) $reason) : null,
                'reviewer_user_id' => $reviewer->id,
                'owner_contract_membership_id' => $membership->id,
                'active_marker' => null,
                'decided_at' => $decidedAt,
            ])->save();

            $superseded = VenueOwnershipClaim::query()
                ->where('venue_id', $venue->id)
                ->whereKeyNot($claim->id)
                ->where('status', VenueOwnershipClaimStatusEnum::PENDING->value)
                ->lockForUpdate()
                ->get();

            foreach ($superseded as $otherClaim) {
                $otherClaim->forceFill([
                    'status' => VenueOwnershipClaimStatusEnum::REJECTED,
                    'decision_reason' => 'Управление площадкой подтверждено по другой заявке.',
                    'reviewer_user_id' => $reviewer->id,
                    'active_marker' => null,
                    'decided_at' => $decidedAt,
                ])->save();
            }

            DB::afterCommit(function () use ($claim, $superseded): void {
                event(new VenueOwnershipClaimApproved($claim->id));
                broadcast(new VenueOwnershipClaimUpdatedBroadcast(
                    $claim->public_id,
                    $claim->status->value,
                    $claim->status->label(),
                ))->toOthers();

                foreach ($superseded as $otherClaim) {
                    event(new VenueOwnershipClaimRejected($otherClaim->id));
                    broadcast(new VenueOwnershipClaimUpdatedBroadcast(
                        $otherClaim->public_id,
                        $otherClaim->status->value,
                        $otherClaim->status->label(),
                    ))->toOthers();
                }
            });

            return $claim->refresh();
        });
    }

    public function reject(VenueOwnershipClaim $claim, User $reviewer, string $reason): VenueOwnershipClaim
    {
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

            DB::afterCommit(function () use ($claim): void {
                event(new VenueOwnershipClaimRejected($claim->id));
                broadcast(new VenueOwnershipClaimUpdatedBroadcast(
                    $claim->public_id,
                    $claim->status->value,
                    $claim->status->label(),
                ))->toOthers();
            });

            return $claim->refresh();
        });
    }

    private function authorizedReviewer(User $reviewer): User
    {
        $reviewer = $reviewer->canonical();

        if (! $reviewer->isConfirmed() || ! $reviewer->system_role->atLeast(UserSystemRoleEnum::ADMIN)) {
            throw new VenueOwnershipClaimException('Рассматривать заявки на управление может только администратор или суперадминистратор.');
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
            'name' => "Управление площадкой: {$venue->name}",
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now(),
            'assigned_by' => $reviewer->id,
            'assigned_at' => now(),
            'assigner' => UserParticipationRoleAssignerEnum::USER,
            'comment' => "Одобрение заявки на управление #{$claim->id}",
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
