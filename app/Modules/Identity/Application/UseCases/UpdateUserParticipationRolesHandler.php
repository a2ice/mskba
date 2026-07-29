<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UpdateUserParticipationRolesHandler
{
    /**
     * @param  array<int, UserParticipationRoleEnum>  $selectedRoles
     */
    public function handle(User $user, array $selectedRoles): User
    {
        return DB::transaction(function () use ($user, $selectedRoles): User {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<string, UserParticipationRole> $existingRoles */
            $existingRoles = $lockedUser->participationRoles(false)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (UserParticipationRole $role): string => $role->role->value);

            $selectedRoleValues = collect($selectedRoles)
                ->map(fn (UserParticipationRoleEnum $role): string => $role->value)
                ->unique()
                ->values();

            foreach ($selectedRoles as $role) {
                $participationRole = $existingRoles->get($role->value);

                if ($participationRole === null) {
                    $lockedUser->participationRoles(false)->create([
                        'role' => $role,
                        'status' => UserParticipationRoleStatusEnum::ACTIVE,
                        'assigned_at' => now(),
                        'assigned_by' => $lockedUser->id,
                        'assigner' => UserParticipationRoleAssignerEnum::USER,
                        'comment' => 'Выбрана пользователем в настройках ролей проекта.',
                    ]);

                    continue;
                }

                if ($participationRole->status === UserParticipationRoleStatusEnum::INACTIVE) {
                    $participationRole->update([
                        'status' => UserParticipationRoleStatusEnum::ACTIVE,
                        'assigned_at' => now(),
                        'expires_at' => null,
                        'assigned_by' => $lockedUser->id,
                        'assigner' => UserParticipationRoleAssignerEnum::USER,
                        'comment' => 'Повторно выбрана пользователем в настройках ролей проекта.',
                    ]);
                }
            }

            $existingRoles
                ->filter(
                    fn (UserParticipationRole $role): bool => $role->status === UserParticipationRoleStatusEnum::ACTIVE
                        && ! $selectedRoleValues->contains($role->role->value),
                )
                ->each(fn (UserParticipationRole $role) => $role->update([
                    'status' => UserParticipationRoleStatusEnum::INACTIVE,
                    'expires_at' => now(),
                    'comment' => 'Отключена пользователем в настройках ролей проекта.',
                ]));

            return $lockedUser->refresh()->load('participationRoles');
        });
    }
}
