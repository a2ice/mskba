<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Tournament\Domain\Enums\TournamentEntrySourceEnum;
use App\Modules\Tournament\Domain\Models\TournamentEntry;
use Illuminate\Support\Collection;

final class TournamentEntryRosterResolver
{
    /** @return Collection<int, User> */
    public function resolveUsers(TournamentEntry $entry): Collection
    {
        $roster = $this->resolve($entry);
        $users = User::query()
            ->whereKey($roster->pluck('user_id'))
            ->with('profile.activeAvatar')
            ->get()
            ->keyBy('id');

        return $roster
            ->map(fn (array $member): ?User => $users->get($member['user_id']))
            ->filter()
            ->keyBy('id');
    }

    /**
     * @return Collection<int, array{user_id: int, source_contract_membership_id: ?int}>
     */
    public function resolve(TournamentEntry $entry): Collection
    {
        if ($entry->source === TournamentEntrySourceEnum::TEAM && $entry->team_id !== null) {
            $roster = ContractMembership::query()
                ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
                ->where('scope_id', $entry->team_id)
                ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
                ->whereJsonContains('sport_roles', TeamMemberTypeEnum::PLAYER->value)
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->orderBy('id')
                ->get(['id', 'user_id'])
                ->map(static fn (ContractMembership $membership): array => [
                    'user_id' => (int) $membership->user_id,
                    'source_contract_membership_id' => $membership->id,
                ]);

            return $this->canonicalize($roster);
        }

        $roster = $entry->members()
            ->orderBy('position')
            ->get(['user_id', 'source_contract_membership_id'])
            ->map(static fn ($member): array => [
                'user_id' => (int) $member->user_id,
                'source_contract_membership_id' => $member->source_contract_membership_id === null
                    ? null
                    : (int) $member->source_contract_membership_id,
            ]);

        return $this->canonicalize($roster);
    }

    /**
     * @param  Collection<int, array{user_id: int, source_contract_membership_id: ?int}>  $roster
     * @return Collection<int, array{user_id: int, source_contract_membership_id: ?int}>
     */
    private function canonicalize(Collection $roster): Collection
    {
        $users = User::query()->whereKey($roster->pluck('user_id'))->get()->keyBy('id');

        return $roster
            ->map(function (array $member) use ($users): ?array {
                $user = $users->get($member['user_id']);
                if ($user === null) {
                    return null;
                }

                $member['user_id'] = (int) $user->canonical()->id;

                return $member;
            })
            ->filter()
            ->unique('user_id')
            ->values();
    }
}
