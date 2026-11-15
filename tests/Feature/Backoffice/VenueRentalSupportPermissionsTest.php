<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\VenueRentalBookings\VenueRentalBookingResource;
use Filament\Facades\Filament;
use App\Filament\Backoffice\Resources\VenueRentalInterests\VenueRentalInterestResource;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueRentalSupportPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function account(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_delegated_viewer_sees_inbox_but_cannot_write(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $viewer = $this->account(UserSystemRoleEnum::MANAGER);
        $requester = $this->account(UserSystemRoleEnum::USER);
        app(BackofficePermissionManager::class)->sync($super, $viewer, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_VENUE_BOOKINGS_VIEW->value,
        ], true);
        $this->actingAs($viewer);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $access = app(VenueBookingBackofficeAccess::class);
        $this->assertTrue($access->canView($viewer));
        $this->assertFalse($access->canManage($viewer));
        $this->assertFalse(VenueResource::canViewAny());
        $this->assertTrue(VenueRentalBookingResource::canViewAny());
        $this->assertTrue(VenueRentalInterestResource::canViewAny());
        $this->get(VenueRentalBookingResource::getUrl('index'))->assertOk();
        $this->get(VenueRentalInterestResource::getUrl('index'))->assertOk();

        $venue = Venue::factory()->create();
        $booking = new VenueBooking(['requester_user_id' => $requester->id, 'venue_id' => $venue->id]);
        $actor = app(CurrentActorResolver::class)->resolve($viewer, null);
        $auth = app(VenueBookingAuthorization::class);
        $auth->assertCanView($actor, $booking, $venue);
        try {
            $auth->assertCanWriteConversation($actor, $booking, $venue);
            $this->fail('Reader cannot write.');
        } catch (VenueBookingTransitionException $e) {
            $this->assertSame('BOOKING_FORBIDDEN', $e->errorCode);
        }
    }

    public function test_delegated_manager_can_intervene_and_plain_filament_login_cannot_view(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->account(UserSystemRoleEnum::MANAGER);
        $requester = $this->account(UserSystemRoleEnum::USER);
        $permissions = app(BackofficePermissionManager::class);
        $permissions->sync($super, $manager, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_VENUE_BOOKINGS_VIEW->value,
            Permission::BACKOFFICE_VENUE_BOOKINGS_MANAGE->value,
        ], true);
        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->assertTrue(app(VenueBookingBackofficeAccess::class)->canManage($manager));
        $venue = Venue::factory()->create();
        $booking = new VenueBooking(['requester_user_id' => $requester->id, 'venue_id' => $venue->id]);
        $actor = app(CurrentActorResolver::class)->resolve($manager, null);
        $auth = app(VenueBookingAuthorization::class);
        $auth->assertCanView($actor, $booking, $venue);
        $auth->assertCanWriteConversation($actor, $booking, $venue);
        $auth->assertCanConfirmBooking($actor, $venue, $booking);
        $auth->assertCanRejectBooking($actor, $venue, $booking);

        $permissions->sync($super, $manager, [Permission::BACKOFFICE_ACCESS->value], true);
        $this->assertFalse(app(VenueBookingBackofficeAccess::class)->canView($manager));
        $this->assertFalse(VenueRentalBookingResource::canViewAny());
        $this->assertFalse(VenueRentalInterestResource::canViewAny());
        $this->get(VenueRentalBookingResource::getUrl('index'))->assertForbidden();
        $this->get(VenueRentalInterestResource::getUrl('index'))->assertForbidden();
    }
}
