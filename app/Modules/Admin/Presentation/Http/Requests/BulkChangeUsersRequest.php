<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use Illuminate\Foundation\Http\FormRequest;

final class BulkChangeUsersRequest extends FormRequest
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
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<int> */
    public function userIds(): array
    {
        return array_map('intval', $this->validated('user_ids'));
    }
}
