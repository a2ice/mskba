<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserSystemRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('manage-user-system-role', $target) ?? false);
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(UserSystemRoleEnum::class)],
        ];
    }

    public function systemRole(): UserSystemRoleEnum
    {
        return UserSystemRoleEnum::from((string) $this->validated('role'));
    }
}
