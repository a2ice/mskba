<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Введите логин, email или телефон.',
            'login.string' => 'Поле логина должно быть строкой.',
            'login.min' => 'Логин должен содержать не менее :min символов.',
            'login.max' => 'Логин не должен превышать :max символов.',
        ];
    }

    public function attributes(): array
    {
        return [
            'login' => 'логин',
        ];
    }
}
