<?php

namespace App\GraphQL\Queries;

use App\Modules\Identity\Application\Services\MobileTokenAuthorizer;
use App\Modules\Identity\Presentation\Http\Resources\Api\V1\OwnUserResource;

final class Me
{
    public function __construct(
        private readonly MobileTokenAuthorizer $authorizer,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(mixed $root, array $args): array
    {
        $user = $this->authorizer->authenticatedUser('profile:read')
            ->loadMissing('profile.activeAvatar', 'participationRoles');
        $data = (new OwnUserResource($user))->toArray(request());
        $profile = $data['profile'];

        return [
            'id' => (string) $data['id'],
            'username' => $data['username'],
            'status' => $data['status'],
            'profile' => $profile === null ? null : [
                'firstName' => $profile['first_name'],
                'lastName' => $profile['last_name'],
                'middleName' => $profile['middle_name'],
                'gender' => $profile['gender'],
                'birthDate' => $profile['birth_date'],
                'avatarUrl' => $profile['avatar_url'],
            ],
            'participationRoles' => $data['participation_roles'],
        ];
    }
}
