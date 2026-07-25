<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserOperationalPermissionsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'permissions' => $this->input('permissions', []),
        ]);
    }

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('manage-user-operational-permissions', $target) ?? false);
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(UserOperationalPermissionEnum::class)],
        ];
    }

    /**
     * @return array<UserOperationalPermissionEnum>
     */
    public function permissions(): array
    {
        return collect($this->validated('permissions'))
            ->map(fn (string $permission): UserOperationalPermissionEnum => UserOperationalPermissionEnum::from($permission))
            ->all();
    }
}
