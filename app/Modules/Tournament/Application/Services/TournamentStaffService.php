<?php

namespace App\Modules\Tournament\Application\Services;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TournamentStaffService
{
    public function __construct(
        private readonly TournamentAccess $access,
        private readonly CreateUserNotificationHandler $notifications,
        private readonly UserPrivacyAccessService $privacy,
    ) {}

    /** @param list<string> $permissionValues */
    public function invite(Tournament $tournament, User $user, Actor $actor, array $permissionValues): ContractMembership
    {
        if (! $this->privacy->allows($user, $actor->user, UserPrivacySettingTypeEnum::GROUP_INVITATIONS)) {
            throw new InvalidArgumentException('Пользователь запретил приглашать себя в команды и другие группы.');
        }

        $membership = DB::transaction(function () use ($tournament, $user, $actor, $permissionValues): ContractMembership {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($locked, $actor, TournamentPermissionEnum::MANAGE_STAFF);
            if ($locked->createdByActor()->where('user_id', $user->id)->exists()) {
                throw new InvalidArgumentException('Создателю турнира договор не требуется.');
            }
            $exists = ContractMembership::query()
                ->where('scope_type', ContractMembershipScopeTypeEnum::TOURNAMENT->value)
                ->where('scope_id', $locked->id)
                ->where('user_id', $user->id)
                ->whereIn('invitation_status', [TeamInvitationStatusEnum::PENDING->value, TeamInvitationStatusEnum::ACCEPTED->value])
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists();
            if ($exists) {
                throw new InvalidArgumentException('У пользователя уже есть активное приглашение или договор.');
            }

            $permissions = $this->normalizedDelegablePermissions($locked, $actor, $permissionValues);
            $contract = Contract::query()->create([
                'family' => ContractFamilyEnum::MEMBERSHIP,
                'name' => 'Ответственный за турнир «'.$locked->title.'»',
                'status' => ContractStatusEnum::ACTIVE,
                'starts_at' => now(),
                'assigned_by' => $actor->user_id,
                'assigned_at' => now(),
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
            $membership = $contract->membership()->create([
                'scope_type' => ContractMembershipScopeTypeEnum::TOURNAMENT,
                'scope_id' => $locked->id,
                'user_id' => $user->id,
                'access_level' => 'responsible',
                'sport_roles' => [],
                'is_captain' => false,
                'is_default_starter' => false,
                'invitation_status' => TeamInvitationStatusEnum::PENDING,
            ]);
            $contract->permissions()->createMany(array_map(
                fn (TournamentPermissionEnum $permission): array => ['permission' => $permission->value],
                $permissions,
            ));

            return $membership;
        });

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $user->id,
            type: UserNotificationTypeEnum::REMINDER,
            title: 'Приглашение в команду турнира',
            body: "Вас приглашают помогать с турниром «{$tournament->title}».",
            actionUrl: route('tournaments.manage', $tournament->routeIdentifier()),
            actionText: 'Ответить',
            payload: ['tournament_id' => $tournament->id, 'contract_membership_id' => $membership->id],
        ));

        return $membership;
    }

    public function respond(Tournament $tournament, ContractMembership $membership, User $user, TeamInvitationStatusEnum $decision): void
    {
        if (! in_array($decision, [TeamInvitationStatusEnum::ACCEPTED, TeamInvitationStatusEnum::DECLINED], true)) {
            throw new InvalidArgumentException('Недопустимый ответ на приглашение.');
        }
        DB::transaction(function () use ($tournament, $membership, $user, $decision): void {
            $locked = ContractMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToTournament($locked, $tournament);
            if ((int) $locked->user_id !== (int) $user->id || $locked->invitation_status !== TeamInvitationStatusEnum::PENDING) {
                throw new InvalidArgumentException('Это приглашение недоступно.');
            }
            $locked->forceFill(['invitation_status' => $decision])->save();
            if ($decision === TeamInvitationStatusEnum::DECLINED) {
                $locked->contract()->update(['status' => ContractStatusEnum::INACTIVE]);
            }
        });
    }

    /** @param list<string> $permissionValues */
    public function updatePermissions(Tournament $tournament, ContractMembership $membership, Actor $actor, array $permissionValues): void
    {
        DB::transaction(function () use ($tournament, $membership, $actor, $permissionValues): void {
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_STAFF);
            $locked = ContractMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToTournament($locked, $lockedTournament);
            $permissions = $this->normalizedDelegablePermissions($lockedTournament, $actor, $permissionValues);
            $locked->contract->permissions()->delete();
            $locked->contract->permissions()->createMany(array_map(
                fn (TournamentPermissionEnum $permission): array => ['permission' => $permission->value],
                $permissions,
            ));
        });
    }

    public function revoke(Tournament $tournament, ContractMembership $membership, Actor $actor): void
    {
        DB::transaction(function () use ($tournament, $membership, $actor): void {
            $lockedTournament = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($lockedTournament, $actor, TournamentPermissionEnum::MANAGE_STAFF);
            $locked = ContractMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsToTournament($locked, $lockedTournament);
            $locked->forceFill(['invitation_status' => TeamInvitationStatusEnum::REVOKED])->save();
            $locked->contract()->update(['status' => ContractStatusEnum::INACTIVE]);
        });
    }

    /** @param list<string> $values
     * @return list<TournamentPermissionEnum>
     */
    private function normalizedDelegablePermissions(Tournament $tournament, Actor $actor, array $values): array
    {
        $permissions = collect($values)->unique()->map(function (string $value): TournamentPermissionEnum {
            return TournamentPermissionEnum::tryFrom($value)
                ?? throw new InvalidArgumentException('Передано неизвестное право турнира.');
        })->values();
        if ($permissions->isEmpty()) {
            throw new InvalidArgumentException('Выберите хотя бы одно право.');
        }
        $forbidden = $permissions->reject(fn (TournamentPermissionEnum $permission): bool => $this->access->allows($tournament, $actor, $permission));
        if ($forbidden->isNotEmpty()) {
            throw new InvalidArgumentException('Нельзя выдать права, которыми вы не обладаете.');
        }

        return $permissions->all();
    }

    private function assertBelongsToTournament(ContractMembership $membership, Tournament $tournament): void
    {
        if ($membership->scope_type !== ContractMembershipScopeTypeEnum::TOURNAMENT
            || (int) $membership->scope_id !== (int) $tournament->id) {
            throw new InvalidArgumentException('Договор не относится к этому турниру.');
        }
    }
}
