<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserBasicDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isConfirmed()
            && ($this->user()?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:1900-01-01'],
            'password' => [
                'nullable',
                'string',
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    try {
                        PasswordVO::fromString((string) $value);
                    } catch (\Throwable $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }

    /** @return array{first_name: ?string, last_name: ?string, middle_name: ?string, birth_date: ?string, password: ?string} */
    public function details(): array
    {
        $validated = $this->validated();
        $target = $this->route('user');
        $profile = $target instanceof \App\Modules\Identity\Domain\Models\User
            ? $target->canonical()->profile
            : null;

        return [
            'first_name' => array_key_exists('first_name', $validated)
                ? $this->nullableTrimmed($validated['first_name']) : $profile?->first_name,
            'last_name' => array_key_exists('last_name', $validated)
                ? $this->nullableTrimmed($validated['last_name']) : $profile?->last_name,
            'middle_name' => array_key_exists('middle_name', $validated)
                ? $this->nullableTrimmed($validated['middle_name']) : $profile?->middle_name,
            'birth_date' => array_key_exists('birth_date', $validated)
                ? $validated['birth_date'] : $profile?->birth_date?->toDateString(),
            'password' => ($validated['password'] ?? '') === '' ? null : (string) $validated['password'],
        ];
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
