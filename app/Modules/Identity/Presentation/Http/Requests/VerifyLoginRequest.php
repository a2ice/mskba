<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string'],
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'challenge.required' => 'Сессия входа истекла. Начните вход заново.',
            'challenge.string' => 'Некорректный токен шага входа.',
            'password.string' => 'Пароль должен быть строкой.',
            'code.string' => 'Одноразовый код должен быть строкой.',
            'code.max' => 'Одноразовый код не должен превышать :max символов.',
        ];
    }

    public function attributes(): array
    {
        return [
            'challenge' => 'challenge',
            'password' => 'пароль',
            'code' => 'одноразовый код',
        ];
    }
}
