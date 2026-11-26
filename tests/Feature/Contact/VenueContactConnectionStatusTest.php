<?php

namespace Tests\Feature\Contact;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as VenueRole;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Venue\Application\Services\VenueContactConnectionStatus;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class VenueContactConnectionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_email_is_not_a_contract_and_revocation_removes_active_status(): void
    {
        config()->set('features.venue_rental.rental_flow', true);
        $super = $this->user(Role::SUPERADMIN);
        $recipient = $this->user(Role::USER);
        $venue = Venue::factory()->create();
        $contact = $venue->contacts()->create(['type' => 'email', 'value' => 'desk@example.test']);
        $recipient->contacts()->create(['type' => 'email', 'value' => $contact->value, 'verified_at' => now()]);
        $resolver = app(VenueContactConnectionStatus::class);
        $this->assertNull($resolver->resolve($super, $venue, $contact)['user']);
        $contact->update(['verified_at' => now()]);
        $result = $resolver->resolve($super, $venue, $contact);
        $this->assertSame($recipient->username, $result['user']['username']);
        $this->assertSame([], $result['contracts']);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $recipient, VenueRole::STAFF, $super, [VenuePermissionEnum::VIEW],
        );
        $this->assertSame($membership->contract_id, $resolver->resolve($super, $venue, $contact)['contracts'][0]['id']);
        app(ManageVenueMembershipHandler::class)->revoke($venue, $membership, $super);
        $this->assertSame([], $resolver->resolve($super, $venue, $contact)['contracts']);
    }

    public function test_staff_only_sees_scoped_invitations_and_no_unrelated_identity(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();
        $super = $this->user(Role::SUPERADMIN);
        $staff = $this->user(Role::MANAGER);
        $recipient = $this->user(Role::USER);
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $contact = $venue->contacts()->create(['type' => 'email', 'value' => 'desk@example.test', 'verified_at' => now()]);
        $foreign = $other->contacts()->create(['type' => 'email', 'value' => $contact->value, 'verified_at' => now()]);
        $recipient->contacts()->create(['type' => 'email', 'value' => $contact->value, 'verified_at' => now()]);
        [$own] = app(AccountInvitationManager::class)->create($super, $contact->value, 'venue', $venue->id, 'staff', '', null, 7);
        app(AccountInvitationManager::class)->create($super, $contact->value, 'venue', $other->id, 'staff', '', null, 7);
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_VENUE_MANAGE, Permission::BACKOFFICE_INVITATION_VIEW] as $permission) {
            $staff->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }
        app(ManageVenueMembershipHandler::class)->grant($venue, $staff, VenueRole::MANAGER, $super, [VenuePermissionEnum::MANAGE_MEMBERSHIPS]);
        $resolver = app(VenueContactConnectionStatus::class);
        $result = $resolver->resolve($staff, $venue, $contact);
        $this->assertSame($own->id, $result['invitation']['id']);
        $this->assertNull($result['user']);
        $this->assertNull($resolver->resolve($staff, $other, $foreign)['invitation']);
        $this->assertNull($resolver->resolve($super, $venue, $foreign)['invitation']);
    }

    private function user(Role $role): User
    {
        return User::factory()->create(['system_role' => $role, 'status' => Status::CONFIRMED]);
    }
}
