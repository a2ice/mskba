<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\DTO\AccountConfirmationStepDTO;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;

final class AccountConfirmationWizardService
{
    /**
     * @return Collection<int, AccountConfirmationStepDTO>
     */
    public function steps(User $user): Collection
    {
        $user->loadMissing('profile', 'participationRoles');

        $role = $this->primaryParticipationRole($user);
        $profile = $user->profile;

        $steps = collect([
            new AccountConfirmationStepDTO(
                key: 'participation_role',
                title: 'Выберите роль участия',
                description: 'Укажите, как вы участвуете в проекте: игрок, тренер, судья, представитель площадки или другая роль.',
                required: true,
                completed: $role !== null,
            ),
        ]);

        if ($role !== null && $this->roleRequiresBirthDateAndGender($role)) {
            $steps->push(new AccountConfirmationStepDTO(
                key: 'birth_date_and_gender',
                title: 'Заполните дату рождения и пол',
                description: 'Для выбранной роли эти данные нужны как часть базового профиля.',
                required: true,
                completed: $profile?->birth_date !== null && $profile?->gender !== null,
            ));
        }

        $steps->push(new AccountConfirmationStepDTO(
            key: 'name',
            title: 'Представьтесь, пожалуйста',
            description: 'Можно указать имя и фамилию, чтобы профиль был понятен другим участникам проекта.',
            required: false,
            completed: filled($profile?->first_name) && filled($profile?->last_name),
        ));

        return $steps;
    }

    public function requiredStepsCompleted(User $user): bool
    {
        return $this->steps($user)
            ->filter(fn (AccountConfirmationStepDTO $step): bool => $step->required)
            ->every(fn (AccountConfirmationStepDTO $step): bool => $step->completed);
    }

    public function primaryParticipationRole(User $user): ?UserParticipationRoleEnum
    {
        $user->loadMissing('participationRoles');

        return $user->participationRoles
            ->first()
            ?->role;
    }

    public function roleRequiresBirthDateAndGender(UserParticipationRoleEnum $role): bool
    {
        return in_array($role, [
            UserParticipationRoleEnum::PLAYER,
            UserParticipationRoleEnum::COACH,
        ], true);
    }
}
