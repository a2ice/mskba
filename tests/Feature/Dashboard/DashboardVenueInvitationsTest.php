<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum as VenueRole;
use App\Modules\Dashboard\Application\Providers\VenueInvitationsProvider;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Invitation\Application\Services\AccountInvitationManager;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class DashboardVenueInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_widget_reuses_delegated_acl_and_never_exposes_other_venues_or_contacts(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('features.venue_rental.rental_flow', true);
        Mail::fake();
        $super = User::factory()->create(['status' => Status::CONFIRMED, 'system_role' => Role::SUPERADMIN]);
        $staff = User::factory()->create(['status' => Status::CONFIRMED, 'system_role' => Role::MANAGER]);
        $first = Venue::factory()->create();
        $second = Venue::factory()->create();
        $invitations = app(AccountInvitationManager::class);
        [$own] = $invitations->create($super, 'own-secret@example.test', 'venue', $first->id, 'staff', 'Private custom title', null, 7);
        [$expired] = $invitations->create($super, 'expired-secret@example.test', 'venue', $first->id, 'staff', '', null, 7);
        [$other] = $invitations->create($super, 'other-secret@example.test', 'venue', $second->id, 'staff', '', null, 7);
        $own->forceFill(['viewed_at' => now(), 'progress' => 'viewed'])->save();
        $expired->forceFill(['expires_at' => now()->subDay()])->save();
        $provider = app(VenueInvitationsProvider::class);
        $this->assertFalse($provider->isAvailable($staff, []));
        $this->assertFalse($provider->isAvailable($super, []));
        foreach ([Permission::BACKOFFICE_ACCESS, Permission::BACKOFFICE_INVITATION_VIEW] as $permission) {
            $staff->operationalPermissions()->create(['permission' => $permission->value, 'is_allowed' => true]);
        }
        $this->assertFalse($provider->isAvailable($staff, []));
        $grant = app(ManageVenueMembershipHandler::class)->grant(
            $first, $staff, VenueRole::MANAGER, $super, [VenuePermissionEnum::MANAGE_MEMBERSHIPS],
        );
        $this->assertTrue($provider->isAvailable($staff, []));
        $data = $provider->getData($staff, []);
        $this->assertSame(2, $data->total);
        $this->assertSame(1, $data->summary['pending']);
        $this->assertSame(1, $data->summary['expired']);
        $this->assertEqualsCanonicalizing([$own->id, $expired->id], array_column($data->items, 'id'));
        $this->assertNotContains($other->id, array_column($data->items, 'id'));
        $items = collect($data->items)->keyBy('id');
        $this->assertSame('Просмотрено', $items[$own->id]['invitation_status']);
        $this->assertSame('Просрочено', $items[$expired->id]['invitation_status']);
        $json = json_encode($data->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('@example.test', $json);
        $this->assertStringNotContainsString('Private custom title', $json);
        $this->assertStringNotContainsString('token_hash', $json);
        $this->assertCount(1, $provider->getData($staff, ['limit' => 1])->items);
        $staff->operationalPermissions()->where('permission', Permission::BACKOFFICE_INVITATION_VIEW->value)
            ->update(['is_allowed' => false]);
        $this->assertFalse($provider->isAvailable($staff->fresh(), []));
        $staff->operationalPermissions()->where('permission', Permission::BACKOFFICE_INVITATION_VIEW->value)
            ->update(['is_allowed' => true]);
        app(ManageVenueMembershipHandler::class)->revoke($first, $grant, $super);
        $this->assertFalse($provider->isAvailable($staff->fresh(), []));
    }
}
