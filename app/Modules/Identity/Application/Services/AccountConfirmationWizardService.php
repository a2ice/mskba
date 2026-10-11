<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\DTO\AccountConfirmationStepDTO;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;

final class AccountConfirmationWizardService
{
    /** @return Collection<int, AccountConfirmationStepDTO> */
    public function steps(User $user): Collection
    {
        $profile = $user->loadMissing('profile')->profile;

        return collect([
            new AccountConfirmationStepDTO(
                key: 'primary_verified_contact',
                title: 'Подтверди основной контакт',
                description: 'Основным может быть подтверждённый Email, Telegram или другой поддерживаемый контакт.',
                required: true,
                completed: $this->primaryVerifiedContact($user) !== null,
            ),
            new AccountConfirmationStepDTO(
                key: 'first_name',
                title: 'Имя',
                description: 'Укажи своё имя.',
                required: true,
                completed: filled(trim((string) $profile?->first_name)),
            ),
            new AccountConfirmationStepDTO(
                key: 'last_name',
                title: 'Фамилия',
                description: 'Укажи свою фамилию.',
                required: true,
                completed: filled(trim((string) $profile?->last_name)),
            ),
            new AccountConfirmationStepDTO(
                key: 'birth_date',
                title: 'Дата рождения',
                description: 'Укажи дату рождения.',
                required: true,
                completed: $profile?->birth_date !== null,
            ),
            new AccountConfirmationStepDTO(
                key: 'gender',
                title: 'Пол',
                description: 'Укажи пол.',
                required: true,
                completed: $profile?->gender !== null,
            ),
        ]);
    }

    public function requiredStepsCompleted(User $user): bool
    {
        return $this->steps($user)->every(fn (AccountConfirmationStepDTO $step): bool => $step->completed);
    }

    // Retained for other role-related flows. Roles are not part of account confirmation.
    public function primaryParticipationRole(User $user): ?UserParticipationRoleEnum
    {
        $user->loadMissing('participationRoles');

        return $user->participationRoles->first()?->role;
    }

    public function primaryContact(User $user): ?Contact
    {
        if (! $user->relationLoaded('contacts')) {
            $user->setRelation('contacts', $user->identityContactsQuery()->get());
        }

        return $user->contacts->first(fn (Contact $contact): bool => $contact->is_primary);
    }

    public function primaryVerifiedContact(User $user): ?Contact
    {
        $contact = $this->primaryContact($user);

        return $contact?->hasBeenVerified() ? $contact : null;
    }

    public function roleRequiresBirthDateAndGender(UserParticipationRoleEnum $role): bool
    {
        return in_array($role, [UserParticipationRoleEnum::PLAYER, UserParticipationRoleEnum::COACH], true);
    }
}
