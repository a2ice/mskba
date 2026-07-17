<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(UserStatusEnum::class)],
        ];
    }

    public function status(): UserStatusEnum
    {
        return UserStatusEnum::from($this->validated('status'));
    }
}
