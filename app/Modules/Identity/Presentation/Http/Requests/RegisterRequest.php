<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
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
        ];
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
        ];
    }

    protected function prepareForValidation(): void
    {
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
