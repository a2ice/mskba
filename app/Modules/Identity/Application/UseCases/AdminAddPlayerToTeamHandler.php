<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Application\Services\TeamRosterService;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminAddPlayerToTeamHandler
{
    public function handle(User $actor, User $target, Team $team, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        // Direct membership changes cannot be silently delegated through an
        // unrelated team permission. Only a superadmin may bypass an invitation.
        abort_unless($actor->system_role === UserSystemRoleEnum::SUPERADMIN, 403);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание назначения (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $team, $reason): AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            abort_if($target->isBlocked() || $target->trashed(), 403);
            if (! $target->hasActiveRole(UserParticipationRoleEnum::PLAYER->value)) {
                throw ValidationException::withMessages(['team' => 'Для включения в состав требуется активная роль игрока.']);
            }
            $team = Team::query()->competitionEligible()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            if (ContractMembership::query()
                ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
                ->where('scope_id', $team->id)
                ->whereIn('user_id', $target->identityIds())
                ->exists()) {
                throw ValidationException::withMessages(['team' => 'Членство или приглашение уже существует. Проверьте текущий статус команды.']);
            }
            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => 'Административное включение игрока в команду «'.$team->name.'»',
                'status' => ContractStatusEnum::ACTIVE,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
                'comment' => $reason,
            ]);
            $membership = $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
                'scope_id' => $team->id,
                'user_id' => $target->id,
                'access_level' => TeamMembershipAccessLevelEnum::PLAYER,
                'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
                'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            ]);
            app(TeamRosterService::class)->synchronizePlayer(
                $team->load('sportProfiles'), $membership->id,
            );

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_player_joined_team',
                'old_values' => [],
                'new_values' => [
                    'team_id' => $team->id,
                    'membership_id' => $membership->id,
                    'status' => TeamInvitationStatusEnum::ACCEPTED->value,
                ],
                'metadata' => ['reason' => $reason, 'contract_id' => $contract->id],
            ]);
        });
    }
}
