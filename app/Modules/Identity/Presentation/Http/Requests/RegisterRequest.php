<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Введите email.',
            'email.string' => 'Email должен быть строкой.',
            'email.email' => 'Введите корректный email.',
            'email.max' => 'Email не должен превышать :max символов.',
        ];
    }

    public function attributes(): array
    {
        return [
            'email' => 'email',
        ];
    }
}
