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

    public function test_delegated_issue_and_manage_are_separate_and_scoped(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();
        $super = $this->user(Role::SUPERADMIN);
        $staff = $this->user(Role::MANAGER);
        $venue = Venue::factory()->create();
        $otherVenue = Venue::factory()->create();
        app(ManageVenueMembershipHandler::class)->grant(
            $venue, $staff, VenueRole::MANAGER, $super,
            [VenuePermissionEnum::MANAGE_MEMBERSHIPS, VenuePermissionEnum::VIEW],
        );
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_INVITATION_VIEW,
            Permission::BACKOFFICE_INVITATION_ISSUE] as $permission) {
            $staff->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }
        $access = app(AccountInvitationAccess::class);
        $manager = app(AccountInvitationManager::class);
        $this->assertSame([$venue->id], $access->issueVenues(Venue::query(), $staff)->pluck('id')->all());
        $this->assertTrue($access->canIssue($staff, $venue, 'staff'));
        foreach (['owner', 'manager', 'booking_operator'] as $role) {
            $this->assertFalse($access->canIssue($staff, $venue, $role));
            $this->assertForbidden(fn () => $manager->create($staff, 'denied@example.test', 'venue', $venue->id, $role, '', null, 7));
        }
        $this->assertForbidden(fn () => $manager->create($staff, 'denied@example.test', 'venue', $otherVenue->id, 'staff', '', null, 7));

        [$record] = $manager->create($staff, 'allowed@example.test', 'venue', $venue->id, 'staff', '', null, 7);
        [$other] = $manager->create($super, 'other@example.test', 'venue', $otherVenue->id, 'staff', '', null, 7);
        $this->assertSame($staff->id, $record->created_by_user_id);
        $this->assertForbidden(fn () => $manager->renew($record, $staff));
        $staff->operationalPermissions()->create(['permission' => Permission::BACKOFFICE_INVITATION_MANAGE->value, 'is_allowed' => true]);
        $this->assertTrue($access->canManage($staff, $record));
        $this->assertFalse($access->canManage($staff, $other));
        $this->assertForbidden(fn () => $manager->revoke($other, $staff));
        $manager->renew($record, $staff);
        $manager->revoke($record->fresh(), $staff);
        $manager->restore($record->fresh(), $staff, 7, 'Повторное приглашение сотрудника');
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame($staff->id, $record->events()->where('event', 'restored')->sole()->details['issuer_id']);

        $staff->operationalPermissions()->where('permission', Permission::BACKOFFICE_INVITATION_ISSUE->value)
            ->update(['is_allowed' => false]);
        $this->assertFalse($access->canIssue($staff, $venue, 'staff'));
        $this->assertTrue($access->canManage($staff, $record));
        $this->assertForbidden(fn () => $manager->create($staff, 'denied@example.test', 'venue', $venue->id, 'staff', '', null, 7));
        $this->assertDatabaseCount('account_invitations', 2);
    }

    public function test_delegated_completion_rechecks_issue_permission_and_venue_contract(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();
        $super = $this->user(Role::SUPERADMIN);
        $staff = $this->user(Role::MANAGER);
        $venue = Venue::factory()->create();
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $staff, VenueRole::MANAGER, $super,
            [VenuePermissionEnum::MANAGE_MEMBERSHIPS, VenuePermissionEnum::VIEW],
        );
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_INVITATION_VIEW,
            Permission::BACKOFFICE_INVITATION_ISSUE] as $permission) {
            $staff->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }
        $manager = app(AccountInvitationManager::class);
        foreach (['allowed', 'permission_revoked', 'contract_revoked'] as $scenario) {
            [$record] = $manager->create($staff, $scenario.'-delegate@example.test', 'venue', $venue->id, 'staff', '', null, 7);
            $target = $this->user(Role::USER, Status::UNCONFIRMED);
            $manager->accept($record, $target);
            $target->createProfile([
                'first_name' => 'Иван', 'last_name' => 'Сотрудник',
                'birth_date' => '1990-01-01', 'gender' => 'male',
            ]);
            $target->confirmAccount();
            if ($scenario === 'permission_revoked') {
                $staff->operationalPermissions()->where('permission', Permission::BACKOFFICE_INVITATION_ISSUE->value)
                    ->update(['is_allowed' => false]);
            } elseif ($scenario === 'contract_revoked') {
                app(ManageVenueMembershipHandler::class)->revoke($venue, $membership, $super);
            }
            $manager->advance($record->fresh(), $target);
            $this->assertSame($scenario === 'allowed' ? 'completed' : 'awaiting_authorization', $record->fresh()->progress);
            $exists = \App\Modules\Contract\Domain\Models\ContractMembership::query()
                ->where('scope_type', 'venue')->where('scope_id', $venue->id)->where('user_id', $target->id)
                ->where('access_level', 'staff')->exists();
            $this->assertSame($scenario === 'allowed', $exists);
            if ($scenario === 'permission_revoked') {
                $staff->operationalPermissions()->where('permission', Permission::BACKOFFICE_INVITATION_ISSUE->value)
                    ->update(['is_allowed' => true]);
            }
        }
    }

    private function assertForbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a forbidden invitation operation.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function user(Role $role, Status $status = Status::CONFIRMED): User
    {
        return User::factory()->create(['system_role' => $role, 'status' => $status]);
    }
}
