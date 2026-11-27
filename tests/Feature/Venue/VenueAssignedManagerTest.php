<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAssignedManagerService;
use App\Modules\Venue\Application\Services\VenueContractInventory;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueAssignedManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class VenueAssignedManagerTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    public function test_assignment_replacement_is_auditable_and_does_not_grant_permissions_or_commission(): void
    {
        $admin = $this->user(Role::SUPERADMIN);
        $one = $this->user(Role::MANAGER);
        $two = $this->user(Role::MANAGER);
        $venue = Venue::factory()->create();
        $service = app(VenueAssignedManagerService::class);
        $first = $service->assign($venue, $one, $admin);
        $this->assertSame($first->id, $service->assign($venue, $one, $admin)->id);
        $this->assertFalse(app(VenueMembershipAccess::class)->allows($one, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS));
        $this->assertDatabaseMissing('venue_commission_agents', ['venue_id' => $venue->id, 'user_id' => $one->id]);
        $second = $service->assign($venue, $two, $admin);
        $this->assertNotNull($first->fresh()->ended_at);
        $this->assertNull($first->fresh()->active_marker);
        $this->assertEquals($admin->id, $first->fresh()->ended_by_user_id);
        $this->assertSame($second->id, $service->current($venue)->id);
        $service->revoke($venue, $first, $admin);
        $this->assertSame($second->id, $service->current($venue)->id);
        $this->assertSame(2, app(VenueContractInventory::class)->assignedManagers($venue, $admin)->count());
        $two->forceFill(['system_role' => Role::USER])->save();
        $this->assertNull($service->current($venue));
        $service->revoke($venue, $second, $admin);
        $this->assertNotNull($second->fresh()->ended_at);
        $this->assertSame(2, VenueAssignedManager::query()->where('venue_id', $venue->id)->count());
    }

    public function test_assignment_requires_portal_staff_and_superadmin_authority(): void
    {
        $admin = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        $ordinary = $this->user(Role::USER);
        $venue = Venue::factory()->create();
        $service = app(VenueAssignedManagerService::class);
        try {
            $service->assign($venue, $manager, $manager);
            $this->fail('Manager must not assign themselves.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            $service->assign($venue, $ordinary, $admin);
            $this->fail('Ordinary user is not a portal manager.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }
        $this->assertDatabaseCount('venue_assigned_managers', 0);
        $this->expectException(HttpException::class);
        app(VenueContractInventory::class)->memberships($venue, $ordinary);
    }
}
