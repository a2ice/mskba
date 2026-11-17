<?php

namespace App\Modules\Identity\Presentation\Http\Resources\Api\V1;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class OwnUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $profile = $user->profile;
        $avatarUrl = $profile?->avatarUrl();

        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'status' => $user->status->value,
            'profile' => $profile === null ? null : [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_name' => $profile->middle_name,
                'gender' => $profile->gender?->value,
                'birth_date' => $profile->birth_date?->format('Y-m-d'),
                'avatar_url' => $avatarUrl === null ? null : url($avatarUrl),
            ],
            'participation_roles' => $user->participationRoles
                ->map(fn ($role): string => $role->role->value)
                ->values()->all(),
        ];
    }
}
