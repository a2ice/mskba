<?php

namespace Tests\Feature\Invitation;

use App\Filament\Backoffice\Resources\AccountInvitations\AccountInvitationResource;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as VenueRole;
use App\Modules\Identity\Application\Services\StaffPermissionProfiles;
use App\Modules\Identity\Application\Services\UserOperationalPermissionChecker;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationAccess;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class AccountInvitationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_permissions_are_opt_in_for_staff_and_outside_user_ceiling(): void
    {
        $profiles = app(StaffPermissionProfiles::class);
        $checker = app(UserOperationalPermissionChecker::class);
        $manager = $this->user(Role::MANAGER);
        $admin = $this->user(Role::ADMIN);
        foreach ([Permission::BACKOFFICE_INVITATION_VIEW, Permission::BACKOFFICE_INVITATION_ISSUE,
            Permission::BACKOFFICE_INVITATION_MANAGE] as $permission) {
            $this->assertTrue($permission->isBackoffice());
            $this->assertTrue($profiles->allowedForRole(Role::MANAGER, $permission));
            $this->assertFalse($profiles->allowedForRole(Role::USER, $permission));
            $this->assertFalse($checker->allows($manager, $permission));
            $this->assertFalse($checker->allows($admin, $permission));
        }
    }

    public function test_delegated_view_is_scoped_to_active_venue_authority(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();
        $super = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        $first = Venue::factory()->create();
        $second = Venue::factory()->create();
        $invitations = app(AccountInvitationManager::class);
        [$own] = $invitations->create($super, 'first@example.test', 'venue', $first->id, 'staff', '', null, 7);
        [$other] = $invitations->create($super, 'second@example.test', 'venue', $second->id, 'staff', '', null, 7);
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_INVITATION_VIEW] as $permission) {
            $manager->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }

        $access = app(AccountInvitationAccess::class);
        $this->assertTrue($access->canViewAny($manager));
        $this->assertFalse($access->canView($manager, $own));
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $first, $manager, VenueRole::MANAGER, $super, [VenuePermissionEnum::MANAGE_MEMBERSHIPS],
        );

        $this->actingAs($manager);
        $this->assertSame([$own->id], AccountInvitationResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(AccountInvitationResource::canView($own));
        $this->assertFalse(AccountInvitationResource::canView($other));
        $this->assertFalse($access->canMutate($manager));
        $this->assertTrue($access->canMutate($super));

        app(ManageVenueMembershipHandler::class)->revoke($first, $membership, $super);
        $this->assertFalse(AccountInvitationResource::canView($own));
        $this->assertSame([], AccountInvitationResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_unconfirmed_and_blocked_staff_cannot_view_even_with_explicit_grants(): void
    {
        $access = app(AccountInvitationAccess::class);
        foreach ([Status::UNCONFIRMED, Status::BLOCKED] as $status) {
            $manager = $this->user(Role::MANAGER, $status);
            foreach ([Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_INVITATION_VIEW] as $permission) {
                $manager->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
            }
            $this->assertFalse($access->canViewAny($manager));
        }
    }

    private function user(Role $role, Status $status = Status::CONFIRMED): User
    {
        return User::factory()->create(['system_role' => $role, 'status' => $status]);
    }
}
