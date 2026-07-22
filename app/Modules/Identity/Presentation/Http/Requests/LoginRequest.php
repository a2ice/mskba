<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'min:3', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable'],
            'redirect_to' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Введите логин или подтверждённый контакт.',
            'login.string' => 'Поле логина должно быть строкой.',
            'login.min' => 'Логин должен содержать не менее :min символов.',
            'login.max' => 'Логин не должен превышать :max символов.',
            'password.required' => 'Введите пароль.',
            'password.string' => 'Пароль должен быть строкой.',
            'password.max' => 'Пароль не должен превышать :max символов.',
        ];
    }

    public function attributes(): array
    {
        return [
            'login' => 'логин',
            'password' => 'пароль',
            'remember' => 'запомнить меня',
        ];
    }
}
