<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Contract\Application\Services\EntityContractManager;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\Services\SportsSectionRules;
use App\Modules\SportsSection\Domain\Enums\SportsSectionAccessLevelEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Support\Facades\DB;

final readonly class ManageSectionCoachHandler
{
    public function __construct(
        private SportsSectionAccess $access,
        private SportsSectionRules $rules,
        private EntityContractManager $contracts,
    ) {}

    /** @param list<SportsSectionPermissionEnum> $permissions */
    public function add(
        SportsSection $section, User $target, User $issuer, array $permissions = [],
        ?string $contractName = null, ?string $expiresAt = null, ?string $notes = null,
    ): ContractMembership
    {
        $target = $this->rules->assertCoach($target);
        $issuer = $issuer->canonical();

        return DB::transaction(function () use ($section, $target, $issuer, $permissions, $contractName, $expiresAt, $notes): ContractMembership {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($issuer, $section);
            $alreadyInvited = ContractMembership::query()
                ->where('scope_type', ContractMembershipScopeTypeEnum::SPORTS_SECTION->value)
                ->where('scope_id', $section->id)
                ->whereIn('user_id', $target->identityIds())
                ->whereIn('invitation_status', [
                    TeamInvitationStatusEnum::ACCEPTED->value,
                    TeamInvitationStatusEnum::PENDING->value,
                ])
                ->whereHas('contract', fn ($query) => $query
                    ->where('status', ContractStatusEnum::ACTIVE->value))
                ->exists();
            if ($alreadyInvited) {
                throw new SportsSectionException('Этот тренер уже подключён к секции или получил приглашение.');
            }
            $values = array_map(
                static fn (SportsSectionPermissionEnum $p): string => $p->value,
                $permissions ?: SportsSectionAccessLevelEnum::MANAGER->defaultPermissions(),
            );
            $membership = $this->contracts->invite(
                ContractMembershipScopeTypeEnum::SPORTS_SECTION,
                $section->id, $issuer, $target,
                $contractName ?: "Тренер секции «{$section->name}»", $values, $expiresAt, $notes,
            );
            $membership->update([
                'access_level' => SportsSectionAccessLevelEnum::MANAGER,
                'sport_roles' => ['coach'],
            ]);

            return $membership->load(['user.profile', 'contract.permissions']);
        });
    }

    /** @param list<SportsSectionPermissionEnum> $permissions */
    public function permissions(SportsSection $section, ContractMembership $membership, User $issuer, array $permissions): ContractMembership
    {
        return DB::transaction(function () use ($section, $membership, $issuer, $permissions): ContractMembership {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($issuer->canonical(), $section);
            $membership = $this->membership($section, $membership, true);
            if ($membership->access_level === SportsSectionAccessLevelEnum::OWNER->value) {
                throw new SportsSectionException('Права владельца секции не ограничиваются.');
            }
            $values = array_values(array_unique(array_map(
                static fn (SportsSectionPermissionEnum $p): string => $p->value, $permissions,
            )));
            $available = $this->contracts->effectivePermissions(
                $issuer->canonical(), ContractMembershipScopeTypeEnum::SPORTS_SECTION, $section->id,
            );
            if ($values === [] || array_diff($values, $available) !== []
                || array_diff($available, $values) === []) {
                throw new SportsSectionException('Нельзя назначить права, которых у вас нет, или полный набор прав владельца.');
            }
            $previous = $membership->contract->permissions()->pluck('permission')->all();
            $isExpansion = array_diff($values, $previous) !== [];
            $this->replacePermissions($membership->contract, $permissions);
            if ($isExpansion && $membership->invitation_status === TeamInvitationStatusEnum::ACCEPTED) {
                $membership->update(['invitation_status' => TeamInvitationStatusEnum::PENDING]);
                $memberId = (int) $membership->user_id;
                DB::afterCommit(static fn () =>
                    app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                        userId: $memberId,
                        type: UserNotificationTypeEnum::REMINDER,
                        title: 'Изменены полномочия в секции',
                        body: 'Для новых полномочий тренера необходимо ваше подтверждение.',
                        actionUrl: route('account.contracts'),
                        actionText: 'Рассмотреть',
                    ))
                );
            }

            return $membership->refresh()->load('contract.permissions');
        });
    }

    public function transferHeadCoach(SportsSection $section, ContractMembership $membership, User $issuer): SportsSection
    {
        return DB::transaction(function () use ($section, $membership, $issuer): SportsSection {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            if (! $this->access->isOwner($issuer->canonical(), $section)) {
                throw new SportsSectionException('Назначить главного тренера может только владелец секции.');
            }
            $membership = $this->membership($section, $membership, false);
            if ($membership->invitation_status !== TeamInvitationStatusEnum::ACCEPTED) {
                throw new SportsSectionException('Сначала тренер должен принять приглашение.');
            }
            $section->update(['head_coach_membership_id' => $membership->id]);

            return $section->refresh()->load('headCoachMembership.user');
        });
    }

    public function remove(SportsSection $section, ContractMembership $membership, User $issuer): void
    {
        DB::transaction(function () use ($section, $membership, $issuer): void {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($section->id);
            $this->authorize($issuer->canonical(), $section);
            $membership = $this->membership($section, $membership, true);
            if ($membership->access_level === SportsSectionAccessLevelEnum::OWNER->value || $section->head_coach_membership_id === $membership->id) {
                throw new SportsSectionException('Владельца или текущего главного тренера нельзя отключить. Сначала назначьте другого главного тренера.');
            }
            $membership->contract->update(['status' => ContractStatusEnum::INACTIVE, 'expires_at' => now()]);
            $membership->update(['invitation_status' => TeamInvitationStatusEnum::REVOKED]);
        });
    }

    private function authorize(User $issuer, SportsSection $section): void
    {
        if (! $this->access->allows($issuer, $section, SportsSectionPermissionEnum::MANAGE_COACHES)) {
            throw new SportsSectionException('Недостаточно прав для управления тренерами секции.');
        }
    }

    private function membership(SportsSection $section, ContractMembership $membership, bool $withContract): ContractMembership
    {
        $query = ContractMembership::query()->lockForUpdate();
        if ($withContract) {
            $query->with('contract');
        }
        $membership = $query->findOrFail($membership->id);
        if ($membership->scope_type !== ContractMembershipScopeTypeEnum::SPORTS_SECTION || $membership->scope_id !== $section->id) {
            throw new SportsSectionException('Тренер не относится к выбранной секции.');
        }
        if (! $withContract) {
            $membership->load('contract');
        }
        if ($membership->contract->status !== ContractStatusEnum::ACTIVE) {
            throw new SportsSectionException('Membership тренера не активна.');
        }

        return $membership;
    }

    /** @param list<SportsSectionPermissionEnum> $permissions */
    private function replacePermissions(Contract $contract, array $permissions): void
    {
        $values = array_values(array_unique(array_map(static fn (SportsSectionPermissionEnum $permission): string => $permission->value, $permissions)));
        $contract->permissions()->delete();
        $contract->permissions()->createMany(array_map(static fn (string $permission): array => ['permission' => $permission], $values));
    }
}
