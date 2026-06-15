<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Application\Services\AccountConfirmationWizardService;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;

final class CompleteAccountConfirmationWizardHandler
{
    public function __construct(
        private readonly AccountConfirmationWizardService $wizard,
    ) {}

    public function handle(
        User $user,
        ?UserParticipationRoleEnum $role,
        ?string $firstName,
        ?string $lastName,
        ?string $middleName,
        ?string $birthDate,
        ?UserGenderEnum $gender,
    ): User {
        return DB::transaction(function () use ($user, $role, $firstName, $lastName, $middleName, $birthDate, $gender): User {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->loadMissing('participationRoles', 'profile');

            $participationRole = $this->wizard->primaryParticipationRole($lockedUser);

            if ($participationRole === null && $role !== null) {
                $lockedUser->participationRoles()->create([
                    'role' => $role,
                    'status' => UserParticipationRoleStatusEnum::ACTIVE,
                    'assigned_at' => now(),
                    'assigned_by' => $lockedUser->id,
                    'assigner' => UserParticipationRoleAssignerEnum::USER,
                    'comment' => 'Выбрана пользователем при подтверждении аккаунта.',
                ]);

                $participationRole = $role;
            }

            $profileData = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'middle_name' => $middleName,
            ];

            if ($participationRole !== null && $this->wizard->roleRequiresBirthDateAndGender($participationRole)) {
                $profileData['birth_date'] = $birthDate;
                $profileData['gender'] = $gender;
            }

            $lockedUser->profile()->updateOrCreate(
                ['user_id' => $lockedUser->id],
                $profileData,
            );

            $lockedUser->refresh()->loadMissing('profile', 'participationRoles');

            if ($lockedUser->status === UserStatusEnum::UNCONFIRMED && $this->wizard->requiredStepsCompleted($lockedUser)) {
                $lockedUser->confirmAccount();
            }

            return $lockedUser->refresh();
        });
    }
}
