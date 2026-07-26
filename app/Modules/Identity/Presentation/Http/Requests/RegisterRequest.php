<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Application\DTO\ProfileDTO;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'min:'.UsernameVO::MIN_LENGTH,
                'max:'.UsernameVO::MAX_LENGTH,
                Rule::unique('users', 'username'),
                $this->domainUsernameRule(),
            ],
            'password' => [
                'required',
                'string',
                'min:'.PasswordVO::MIN_LENGTH,
                'max:'.PasswordVO::MAX_LENGTH,
                'confirmed',
                $this->domainPasswordRule(),
            ],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::enum(UserGenderEnum::class)],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'role' => ['nullable', 'string', Rule::enum(UserParticipationRoleEnum::class)],
            'privacy_consent' => ['accepted'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function participantRole(): ?UserParticipationRoleEnum
    {
        $role = $this->validated('role');

        return is_string($role) && $role !== ''
            ? UserParticipationRoleEnum::tryFrom($role)
            : null;
    }

    public function profile(): ProfileDTO
    {
        return new ProfileDTO(
            firstName: $this->nullableString('first_name'),
            lastName: $this->nullableString('last_name'),
            middleName: $this->nullableString('middle_name'),
            gender: $this->gender(),
            birthDate: $this->birthDate(),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Введите логин.',
            'username.string' => 'Логин должен быть строкой.',
            'username.min' => 'Логин должен быть не менее :min символов.',
            'username.max' => 'Логин не должен превышать :max символов.',
            'username.unique' => 'Этот логин уже занят.',
            'password.required' => 'Введите пароль.',
            'password.string' => 'Пароль должен быть строкой.',
            'password.min' => 'Пароль должен быть не менее :min символов.',
            'password.max' => 'Пароль не должен превышать :max символов.',
            'password.confirmed' => 'Пароль и подтверждение пароля не совпадают.',
            'role.string' => 'Роль участия должна быть строкой.',
            'role.enum' => 'Выберите доступную роль участия.',
            'privacy_consent.accepted' => 'Для регистрации необходимо согласие на обработку персональных данных.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'username' => 'логин',
            'password' => 'пароль',
            'password_confirmation' => 'подтверждение пароля',
            'role' => 'роль участия',
            'privacy_consent' => 'согласие на обработку персональных данных',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('role') && $this->has('participantRole')) {
            $this->merge([
                'role' => $this->input('participantRole'),
            ]);
        }

        if (! $this->has('username')) {
            return;
        }

        $username = trim((string) $this->input('username'));

        try {
            $username = UsernameVO::fromString($username)->value;
        } catch (InvalidIdentityValueException) {
            // Оставляем trim-значение, чтобы domainUsernameRule отдал нормальную ошибку.
        }

        $this->merge([
            'username' => $username,
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson() || $this->ajax() || $this->wantsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function gender(): ?UserGenderEnum
    {
        $gender = $this->validated('gender');

        return is_string($gender) && $gender !== ''
            ? UserGenderEnum::tryFrom($gender)
            : null;
    }

    private function birthDate(): ?CarbonImmutable
    {
        $birthDate = $this->validated('birth_date');

        if (! is_string($birthDate) || trim($birthDate) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $birthDate);

        return $date instanceof CarbonImmutable
            ? $date->startOfDay()
            : null;
    }

    private function domainUsernameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            try {
                UsernameVO::fromString($value);
            } catch (InvalidIdentityValueException $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    private function domainPasswordRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            try {
                PasswordVO::fromString($value);
            } catch (InvalidIdentityValueException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
