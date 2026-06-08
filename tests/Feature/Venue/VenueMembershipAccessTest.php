<?php

namespace Tests\Feature\Venue;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueMembershipAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_has_full_bootstrap_access_until_venue_has_active_owner_membership(): void
    {
        $creator = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_user_id' => $creator->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
        ]);

        $access = app(VenueAccessResolver::class);

        $this->assertTrue($access->canView($creator, $venue));
        $this->assertTrue($access->canEdit($creator, $venue));
        $this->assertTrue($access->canEditSchedule($creator, $venue));
    }

    public function test_creator_bootstrap_access_stops_when_active_owner_membership_exists(): void
    {
        $creator = User::factory()->create();
        $owner = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_user_id' => $creator->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
        ]);

        $this->createVenueMembership($owner, $venue, VenueMembershipAccessLevelEnum::OWNER);

        $access = app(VenueAccessResolver::class);

        $this->assertFalse($access->canView($creator, $venue));
        $this->assertFalse($access->canEdit($creator, $venue));
        $this->assertFalse($access->canEditSchedule($creator, $venue));
        $this->assertTrue($access->canEdit($owner, $venue));
        $this->assertTrue($access->canEditSchedule($owner, $venue));
    }

    public function test_membership_uses_saved_permission_snapshot_not_access_level_defaults(): void
    {
        $admin = User::factory()->create();
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::UNCONFIRMED->value,
        ]);

        $this->createVenueMembership(
            user: $admin,
            venue: $venue,
            accessLevel: VenueMembershipAccessLevelEnum::ADMIN,
            permissions: [
                VenuePermissionEnum::VIEW,
                VenuePermissionEnum::EDIT,
            ],
        );

        $access = app(VenueAccessResolver::class);

        $this->assertTrue($access->canView($admin, $venue));
        $this->assertTrue($access->canEdit($admin, $venue));
        $this->assertFalse($access->canEditSchedule($admin, $venue));
    }

    /**
     * @param array<VenuePermissionEnum>|null $permissions
     */
    private function createVenueMembership(
        User $user,
        Venue $venue,
        VenueMembershipAccessLevelEnum $accessLevel,
        ?array $permissions = null,
    ): Contract {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP->value,
            'number' => fake()->unique()->bothify('TEST-####-????'),
            'name' => 'Test venue membership',
            'status' => ContractStatusEnum::ACTIVE->value,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER->value,
        ]);

        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE->value,
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => $accessLevel->value,
        ]);

        collect($permissions ?? $accessLevel->defaultPermissions())
            ->each(fn (VenuePermissionEnum $permission) => $contract->permissions()->create([
                'permission' => $permission->value,
            ]));

        return $contract;
    }
}
