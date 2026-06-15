<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Application\Services\AccountConfirmationWizardService;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteAccountConfirmationWizardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['nullable', Rule::enum(UserParticipationRoleEnum::class)],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(UserGenderEnum::class)],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $user = $this->user();

            if ($user === null) {
                return;
            }

            $wizard = app(AccountConfirmationWizardService::class);
            $currentRole = $wizard->primaryParticipationRole($user);
            $submittedRole = $this->role();
            $role = $currentRole ?? $submittedRole;

            if ($currentRole === null && $submittedRole === null) {
                $validator->errors()->add('role', 'Выберите роль участия.');

                return;
            }

            if ($wizard->primaryVerifiedContact($user) === null) {
                $validator->errors()->add('contact', 'Подтвердите основной контакт.');
            }

            if ($role !== null && $wizard->roleRequiresBirthDateAndGender($role)) {
                if (! is_string($this->input('birth_date')) || trim($this->input('birth_date')) === '') {
                    $validator->errors()->add('birth_date', 'Укажите дату рождения.');
                }

                if ($this->gender() === null) {
                    $validator->errors()->add('gender', 'Выберите пол.');
                }
            }
        });
    }

    public function role(): ?UserParticipationRoleEnum
    {
        $role = $this->input('role');

        return is_string($role) && $role !== ''
            ? UserParticipationRoleEnum::tryFrom($role)
            : null;
    }

    public function birthDate(): ?string
    {
        $birthDate = $this->validated('birth_date');

        return is_string($birthDate) && trim($birthDate) !== ''
            ? $birthDate
            : null;
    }

    public function gender(): ?UserGenderEnum
    {
        $gender = $this->input('gender');

        return is_string($gender) && $gender !== ''
            ? UserGenderEnum::tryFrom($gender)
            : null;
    }

    public function firstName(): ?string
    {
        $firstName = $this->validated('first_name');

        return is_string($firstName) && trim($firstName) !== ''
            ? trim($firstName)
            : null;
    }

    public function lastName(): ?string
    {
        $lastName = $this->validated('last_name');

        return is_string($lastName) && trim($lastName) !== ''
            ? trim($lastName)
            : null;
    }

    public function middleName(): ?string
    {
        $middleName = $this->validated('middle_name');

        return is_string($middleName) && trim($middleName) !== ''
            ? trim($middleName)
            : null;
    }
}
