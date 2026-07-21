<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Exceptions\InvalidIdentityValueException;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountPasswordRequest extends FormRequest
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
            'current_password' => ['nullable', 'string', 'max:'.PasswordVO::MAX_LENGTH],
            'password' => [
                'required',
                'string',
                'min:'.PasswordVO::MIN_LENGTH,
                'max:'.PasswordVO::MAX_LENGTH,
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    try {
                        PasswordVO::fromString($value);
                    } catch (InvalidIdentityValueException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Введите новый пароль.',
            'password.confirmed' => 'Пароль и подтверждение пароля не совпадают.',
        ];
    }
}
