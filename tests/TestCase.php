<?php

namespace Tests;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    /**
     * Existing feature tests historically used a confirmed user as an organizer
     * fixture before creation operational permissions existed. Keep those tests
     * focused on their own behavior by explicitly granting the new permissions.
     * Permission-specific tests opt out and exercise the real defaults.
     */
    protected bool $grantCreationPermissionsToConfirmedTestActors = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiting is covered by the framework; sharing one test IP must not
        // make unrelated feature tests influence each other.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        if (
            $this->grantCreationPermissionsToConfirmedTestActors
            && $user instanceof User
            && $user->isConfirmed()
            && ! $user->isBlocked()
            && ! $user->trashed()
        ) {
            $canonical = $user->canonical();

            foreach ([
                UserOperationalPermissionEnum::CREATE_EVENT,
                UserOperationalPermissionEnum::CREATE_TOURNAMENT,
            ] as $permission) {
                UserOperationalPermission::query()->firstOrCreate(
                    [
                        'user_id' => $canonical->id,
                        'permission' => $permission->value,
                    ],
                    [
                        'is_allowed' => true,
                    ],
                );
            }
        }

        return parent::actingAs($user, $guard);
    }
}
