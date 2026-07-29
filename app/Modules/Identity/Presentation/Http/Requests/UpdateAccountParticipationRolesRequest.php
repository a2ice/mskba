<?php

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAccountParticipationRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowedRoles = implode(',', array_column(UserParticipationRoleEnum::cases(), 'value'));

        return [
            'roles' => ['required', 'array:'.$allowedRoles],
            'roles.*' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, UserParticipationRoleEnum>
     */
    public function selectedRoles(): array
    {
        return collect(UserParticipationRoleEnum::cases())
            ->filter(fn (UserParticipationRoleEnum $role): bool => $this->boolean('roles.'.$role->value))
            ->values()
            ->all();
    }
}
