<?php

namespace App\Filament\Backoffice\Resources\Teams;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Backoffice member operations use the same contracts and sport lineups as
 * the public portal. Neither writes a "team_members" shadow table nor purges
 * game history when a participant leaves.
 */
final class BackofficeTeamMembers
{
    private function validateMember(array $data, bool $creating): array
    {
        $rules = [
            'access_level' => ['required', Rule::in(['responsible', 'captain', 'coach', 'player'])],
            'sport_roles' => ['required', 'array', 'min:1'],
            'sport_roles.*' => ['required', 'distinct', Rule::enum(TeamMemberTypeEnum::class)],
            'is_captain' => ['boolean'],
            'jersey_number' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
        if ($creating) {
            $rules['user_id'] = ['required', 'integer', Rule::exists('users', 'id')
                ->whereNull('canonical_user_id')->whereNull('deleted_at')];
        }
        $data = Validator::make($data, $rules)->validate();
        $roles = $data['sport_roles'];
        if (($data['is_captain'] ?? false) && ! in_array('player', $roles, true)) {
            throw ValidationException::withMessages(['sport_roles' => 'Капитан обязательно должен иметь спортивную роль игрока.']);
        }
        if ($data['access_level'] === 'captain' && ! in_array('player', $roles, true)) {
            throw ValidationException::withMessages(['access_level' => 'Уровень капитана доступен только игроку.']);
        }
        return $data;
    }

    private function acceptedMembers(Team $team)
    {
        return $team->memberships()
            ->where('invitation_status', TeamInvitationStatusEnum::ACCEPTED->value)
            ->whereHas('contract', fn ($q) => $q->where('status', ContractStatusEnum::ACTIVE->value));
    }

    private function checkJersey(Team $team, ?int $number, ?int $exceptId = null): void
    {
        if ($number === null) return;
        if ($this->acceptedMembers($team)->where('jersey_number', $number)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['jersey_number' => "Игровой номер {$number} уже занят."]);
        }
    }

    private function chooseCaptain(Team $team, ContractMembership $membership): void
    {
        $others = $this->acceptedMembers($team)->whereKeyNot($membership->id)
            ->where('is_captain', true)->lockForUpdate()->get();
        foreach ($others as $previous) {
            $previous->update([
                'is_captain' => false,
                'access_level' => $previous->access_level === TeamMembershipAccessLevelEnum::CAPTAIN->value
                    ? TeamMembershipAccessLevelEnum::PLAYER->value : $previous->access_level,
            ]);
        }
    }

    public function add(Team $team, array $data): ContractMembership
    {
        abort_unless(TeamResource::canEdit($team), 403);
        $data = $this->validateMember($data, true);
        return DB::transaction(function () use ($team, $data): ContractMembership {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if ($this->acceptedMembers($team)->whereIn('user_id', $user->identityIds())->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Пользователь уже состоит в команде.']);
            }
            $this->checkJersey($team, $data['jersey_number'] ?? null);
            $actor = auth('web')->user()->canonical();
            $captain = (bool) ($data['is_captain'] ?? false) || $data['access_level'] === 'captain';
            $level = $captain && $data['access_level'] === 'player' ? 'captain' : $data['access_level'];
            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => 'Участие в команде «'.$team->name.'»',
                'status' => ContractStatusEnum::ACTIVE,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            $membership = $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                'scope_id' => $team->id,
                'user_id' => $user->id,
                'access_level' => $level,
                'sport_roles' => $data['sport_roles'],
                'jersey_number' => $data['jersey_number'] ?? null,
                'is_captain' => $captain,
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ]);
            if ($captain) $this->chooseCaptain($team, $membership);
            if (in_array('player', $data['sport_roles'], true)) {
                $team->load('sportProfiles');
                app(TeamRosterService::class)->synchronizePlayer($team, $membership->id);
            }
            return $membership;
        });
    }

    public function update(Team $team, ContractMembership $membership, array $data): ContractMembership
    {
        abort_unless(TeamResource::canEdit($team), 403);
        $data = $this->validateMember($data, false);
        return DB::transaction(function () use ($team, $membership, $data): ContractMembership {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $membership = $this->acceptedMembers($team)->whereKey($membership->id)
                ->lockForUpdate()->firstOrFail();
            $owner = $membership->access_level === TeamMembershipAccessLevelEnum::OWNER->value;
            $player = in_array('player', $data['sport_roles'], true);
            $captain = $player && ((bool) ($data['is_captain'] ?? false) || $data['access_level'] === 'captain');
            $this->checkJersey($team, $data['jersey_number'] ?? null, $membership->id);
            if ($membership->is_captain && ! $captain) {
                throw ValidationException::withMessages(['is_captain' =>
                    'Сначала назначьте другого капитана команды.']);
            }
            if ($captain) $this->chooseCaptain($team, $membership);
            $level = $owner ? 'owner' : ($captain && $data['access_level'] === 'player'
                ? 'captain' : $data['access_level']);
            $membership->update([
                'access_level' => $level,
                'sport_roles' => $data['sport_roles'],
                'is_captain' => $captain,
                'jersey_number' => $data['jersey_number'] ?? null,
                'is_default_starter' => $player && (bool) $membership->is_default_starter,
            ]);
            if ($player) {
                $team->load('sportProfiles');
                app(TeamRosterService::class)->synchronizePlayer($team, $membership->id);
            } else {
                $membership->sportLineupAssignments()->delete();
            }
            return $membership->refresh();
        });
    }

    public function remove(Team $team, ContractMembership $membership): void
    {
        abort_unless(TeamResource::canEdit($team), 403);
        DB::transaction(function () use ($team, $membership): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $membership = $this->acceptedMembers($team)->whereKey($membership->id)
                ->lockForUpdate()->firstOrFail();
            if ($membership->access_level === 'owner') {
                throw ValidationException::withMessages(['member' => 'Владельца нельзя исключить.']);
            }
            if ($membership->is_captain) {
                throw ValidationException::withMessages(['member' => 'Сначала назначьте нового капитана.']);
            }
            $membership->sportLineupAssignments()->delete();
            $membership->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED,
                'is_default_starter' => false]);
            $membership->contract()->update(['status' => ContractStatusEnum::INACTIVE]);
        });
    }

    /** @param list<int|string> $starterIds */
    public function saveLineup(Team $team, TeamSportTypeEnum $sport, array $starterIds): void
    {
        abort_unless(TeamResource::canEdit($team), 403);
        $ids = array_map('intval', $starterIds);
        $all = $this->acceptedMembers($team)->withSportRole(TeamMemberTypeEnum::PLAYER)
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $all)) {
            throw ValidationException::withMessages(['starters' => 'Выбраны посторонние или повторяющиеся участники.']);
        }
        $reserveIds = array_values(array_diff($all, $ids));
        try {
            app(TeamRosterService::class)->save($team, $sport, $ids, $reserveIds);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['starters' => $e->getMessage()]);
        }
    }
}
