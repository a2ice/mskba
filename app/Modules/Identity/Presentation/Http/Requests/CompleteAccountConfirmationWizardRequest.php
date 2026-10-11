<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Application\Services\AccountConfirmationWizardService;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CompleteAccountConfirmationWizardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->canonical()->isBlocked();
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if (trim((string) $value) === '') $fail('Укажи имя.');
            }],
            'last_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if (trim((string) $value) === '') $fail('Укажи фамилию.');
            }],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', Rule::enum(UserGenderEnum::class)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $user = $this->user()?->canonical();
            if ($user === null) return;

            if (app(AccountConfirmationWizardService::class)->primaryVerifiedContact($user) === null) {
                $validator->errors()->add('contact', 'Подтверди основной контакт.');
            }
        });
    }

    public function firstName(): string
    {
        return trim($this->validated('first_name'));
    }

    public function lastName(): string
    {
        return trim($this->validated('last_name'));
    }

    public function birthDate(): string
    {
        return $this->validated('birth_date');
    }

    public function gender(): UserGenderEnum
    {
        return UserGenderEnum::from($this->validated('gender'));
    }
}
