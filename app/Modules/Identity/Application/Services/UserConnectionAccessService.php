<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contract\Application\Services\ContractAssurance;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Models\SectionTraineeMembership;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Database\Eloquent\Builder;

 /**
  * A connection is mutual, not an invitation or an manually maintained allow-list.
  * Two users are connected while they both belong to the same active team or
  * section (accepted coach contracts or active trainee memberships).
  */
final class UserConnectionAccessService
{
    public function allows(User $subject, User $viewer): bool
    {
        $subject = $subject->canonical();
        $viewer = $viewer->canonical();

        if ($subject->isSameIdentity($viewer)
            || $subject->trashed() || $viewer->trashed()
            || $subject->isBlocked() || $viewer->isBlocked()) {
            return false;
        }

        $subjectScopes = $this->groupScopes($subject);

        return $subjectScopes !== []
            && array_intersect_key($subjectScopes, $this->groupScopes($viewer)) !== [];
    }

    /** @return list<int> Canonical user IDs for server-side candidate search. */
    public function connectedCanonicalIds(User $viewer): array
    {
        $viewer = $viewer->canonical();
        $scopes = $this->groupScopes($viewer);
        if ($scopes === []) {
            return [];
        }

        $teamIds = [];
        $sectionIds = [];
        foreach ($scopes as $key => $scopeId) {
            if (str_starts_with($key, 'team:')) {
                $teamIds[] = $scopeId;
            } else {
                $sectionIds[] = $scopeId;
            }
        }

        $memberIds = $this->activeContractMembers()
            ->where(function (Builder $query) use ($teamIds, $sectionIds): void {
                if ($teamIds !== []) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
                        ->whereIn('scope_id', $teamIds));
                }
                if ($sectionIds !== []) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value)
                        ->whereIn('scope_id', $sectionIds));
                }
            })
            ->pluck('user_id')
            ->all();

        if ($sectionIds !== []) {
            $memberIds = array_merge($memberIds, SectionTraineeMembership::query()
                ->whereIn('sports_section_id', $sectionIds)
                ->where('status', TraineeMembershipStatusEnum::ACTIVE->value)
                ->whereHas('section')
                ->pluck('user_id')
                ->all());
        }

        if ($memberIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', array_unique($memberIds))
            ->where('status', '!=', 'blocked')
            ->get(['id', 'canonical_user_id'])
            ->map(fn (User $user): int => (int) ($user->canonical_user_id ?: $user->id))
            ->reject(fn (int $id): bool => in_array($id, $viewer->identityIds(), true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function groupScopes(User $user): array
    {
        $user = $user->canonical();
        if ($user->trashed() || $user->isBlocked()) {
            return [];
        }

        $groups = [];
        foreach ($this->activeContractMembers()
            ->whereIn('user_id', $user->identityIds())
            ->get(['scope_type', 'scope_id']) as $membership) {
            $groups[$membership->scope_type->value.':'.$membership->scope_id] = (int) $membership->scope_id;
        }

        $sectionIds = SectionTraineeMembership::query()
            ->whereIn('user_id', $user->identityIds())
            ->where('status', TraineeMembershipStatusEnum::ACTIVE->value)
            ->whereHas('section')
            ->pluck('sports_section_id');
        foreach ($sectionIds as $id) {
            $groups['sports_section:'.$id] = (int) $id;
        }

        return $groups;
    }

    /** @return Builder<ContractMembership> */
    private function activeContractMembers(): Builder
    {
        $now = now();

        return ContractMembership::query()
            ->whereIn('scope_type', [
                ContractMembershipScopeTypeEnum::TEAM->value,
                ContractMembershipScopeTypeEnum::SPORTS_SECTION->value,
            ])
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn (Builder $query) => $query
                ->where('family', ContractFamilyEnum::MEMBERSHIP->value)
                ->where('status', ContractStatusEnum::ACTIVE->value)
                ->where(fn (Builder $q) => app(ContractAssurance::class)->constrain($q))
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now)))
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $q) => $q
                    ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
                    ->whereIn('scope_id', Team::query()->select('id')))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value)
                        ->whereIn('scope_id', SportsSection::query()->select('id')));
            });
    }
}
