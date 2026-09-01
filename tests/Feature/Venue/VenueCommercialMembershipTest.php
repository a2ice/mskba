<?php

namespace Tests\Feature\Venue;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Exceptions\VenueMembershipException;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCommercialMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
    }

    public function test_commercial_role_matrix_uses_saved_permission_snapshots(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $operator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $finance = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $this->createMembership($owner, $venue, VenueMembershipAccessLevelEnum::OWNER);
        $handler = app(ManageVenueMembershipHandler::class);

        $handler->grant($venue, $operator, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $owner);
        $handler->grant($venue, $finance, VenueMembershipAccessLevelEnum::FINANCE_VIEWER, $owner);
        $access = app(VenueCommercialAccess::class);

        $this->assertTrue($access->allows($owner, $venue, VenuePermissionEnum::MANAGE_MEMBERSHIPS));
        $this->assertTrue($access->allows($operator, $venue, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $this->assertFalse($access->allows($operator, $venue, VenuePermissionEnum::VIEW_PAYMENTS));
        $this->assertTrue($access->allows($finance, $venue, VenuePermissionEnum::VIEW_PAYMENTS));
        $this->assertFalse($access->allows($finance, $venue, VenuePermissionEnum::CONFIRM_PAYMENTS));
        $this->assertFalse($access->allows($finance, $venue, VenuePermissionEnum::EDIT));
    }

    public function test_bootstrap_creator_card_permissions_do_not_grant_commercial_access(): void
    {
        $creator = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($creator, null)->id,
        ]);

        $this->assertFalse(app(VenueCommercialAccess::class)->allows(
            $creator,
            $venue,
            VenuePermissionEnum::VIEW_BOOKING_REQUESTS,
        ));
    }

    public function test_owner_can_grant_change_and_soft_revoke_role_with_immediate_access_change(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $target = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $this->createMembership($owner, $venue, VenueMembershipAccessLevelEnum::OWNER);
        $handler = app(ManageVenueMembershipHandler::class);

        $membership = $handler->grant($venue, $target, VenueMembershipAccessLevelEnum::FINANCE_VIEWER, $owner, [
            VenuePermissionEnum::VIEW,
            VenuePermissionEnum::VIEW_PAYMENTS,
            VenuePermissionEnum::CONFIRM_PAYMENTS,
        ]);
        $this->assertTrue(app(VenueCommercialAccess::class)->allows($target, $venue, VenuePermissionEnum::CONFIRM_PAYMENTS));

        $membership = $handler->change($venue, $membership, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $owner);
        $this->assertTrue(app(VenueCommercialAccess::class)->allows($target, $venue, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $this->assertFalse(app(VenueCommercialAccess::class)->allows($target, $venue, VenuePermissionEnum::VIEW_PAYMENTS));

        $handler->revoke($venue, $membership, $owner);
        $this->assertFalse(app(VenueCommercialAccess::class)->allows($target, $venue, VenuePermissionEnum::DECIDE_BOOKING_REQUESTS));
        $this->assertSame(ContractStatusEnum::INACTIVE, $membership->contract->refresh()->status);
    }

    public function test_manager_with_explicit_manage_memberships_permission_can_grant_role(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $manager = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $target = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $this->createMembership($owner, $venue, VenueMembershipAccessLevelEnum::OWNER);
        $handler = app(ManageVenueMembershipHandler::class);
        $handler->grant($venue, $manager, VenueMembershipAccessLevelEnum::MANAGER, $owner, [
            ...VenueMembershipAccessLevelEnum::MANAGER->defaultPermissions(),
            VenuePermissionEnum::MANAGE_MEMBERSHIPS,
        ]);

        $membership = $handler->grant($venue, $target, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $manager);

        $this->assertSame($target->id, $membership->user_id);
    }

    public function test_membership_from_another_venue_cannot_be_changed_or_revoked(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $target = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $firstVenue = Venue::factory()->create();
        $secondVenue = Venue::factory()->create();
        $this->createMembership($owner, $firstVenue, VenueMembershipAccessLevelEnum::OWNER);
        $this->createMembership($owner, $secondVenue, VenueMembershipAccessLevelEnum::OWNER);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $firstVenue,
            $target,
            VenueMembershipAccessLevelEnum::BOOKING_OPERATOR,
            $owner,
        );

        $this->expectException(VenueMembershipException::class);
        app(ManageVenueMembershipHandler::class)->revoke($secondVenue, $membership, $owner);
    }

    public function test_owner_cannot_be_revoked_or_changed_by_membership_flow(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $membership = $this->createMembership($owner, $venue, VenueMembershipAccessLevelEnum::OWNER);

        try {
            app(ManageVenueMembershipHandler::class)->revoke($venue, $membership, $owner);
            $this->fail('The owner must not be revoked without transfer.');
        } catch (VenueMembershipException) {
            $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->refresh()->status);
        }

        $this->expectException(VenueMembershipException::class);
        app(ManageVenueMembershipHandler::class)->change(
            $venue,
            $membership,
            VenueMembershipAccessLevelEnum::MANAGER,
            $owner,
        );
    }

    public function test_superadmin_override_is_allowed_and_auditable_in_contract_comment(): void
    {
        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $target = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();

        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue,
            $target,
            VenueMembershipAccessLevelEnum::BOOKING_OPERATOR,
            $superadmin,
        );

        $this->assertStringContainsString('Emergency superadmin override', (string) $membership->contract->comment);
    }

    public function test_http_management_is_feature_gated_and_rechecks_venue_permission(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $target = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $this->createMembership($owner, $venue, VenueMembershipAccessLevelEnum::OWNER);

        config()->set('features.venue_rental.rental_flow', false);
        $this->actingAs($owner)
            ->getJson(route('account.venues.commercial-memberships.index', $venue))
            ->assertNotFound()
            ->assertJsonPath('code', 'feature_disabled');

        config()->set('features.venue_rental.rental_flow', true);
        $this->actingAs($stranger)
            ->post(route('account.venues.commercial-memberships.store', $venue), [
                'user_id' => $target->id,
                'role' => VenueMembershipAccessLevelEnum::BOOKING_OPERATOR->value,
                'permissions' => [VenuePermissionEnum::VIEW->value],
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('contract_memberships', 1);

        $this->actingAs($owner)
            ->get(route('account.venues.commercial-memberships.index', $venue))
            ->assertOk();
        $this->actingAs($owner)
            ->post(route('account.venues.commercial-memberships.store', $venue), [
                'user_id' => $target->id,
                'role' => VenueMembershipAccessLevelEnum::BOOKING_OPERATOR->value,
                'permissions' => [
                    VenuePermissionEnum::VIEW->value,
                    VenuePermissionEnum::VIEW_BOOKING_REQUESTS->value,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseCount('contract_memberships', 2);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $target->id,
            'title' => 'Выдана роль площадки',
        ]);
    }

    public function test_internal_superadmin_sees_rental_sections_in_venue_management_navigation(): void
    {
        config()->set('features.venue_rental.portal', true);
        config()->set('features.venue_rental_rollout.mode', 'internal');

        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => app(CurrentActorResolver::class)->resolve($superadmin, null)->id,
        ]);

        $response = $this->actingAs($superadmin)
            ->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertOk();

        $response
            ->assertSee(route('account.venues.booking-policy.edit', $venue), false)
            ->assertSee(route('account.venues.commercial-memberships.index', $venue), false)
            ->assertSee(route('account.venue-bookings.inbox', ['venue_id' => $venue->id]), false);

        $this->actingAs($superadmin)
            ->get(route('account.venues.booking-policy.edit', $venue))
            ->assertOk()
            ->assertSee('Управление площадкой');
    }

    private function createMembership(
        User $user,
        Venue $venue,
        VenueMembershipAccessLevelEnum $role,
    ): ContractMembership {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $membership = $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => $role->value,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (VenuePermissionEnum $permission): array => ['permission' => $permission->value],
            $role->defaultPermissions(),
        ));

        return $membership->load('contract.permissions');
    }
}
