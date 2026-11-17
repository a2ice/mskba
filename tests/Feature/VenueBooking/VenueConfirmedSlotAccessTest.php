<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingTransition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueConfirmedSlotAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.portal', true);
        config()->set('features.venue_rental.conversations', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-17 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirmed_slot_discloses_booking_link_only_to_customer_superadmin_and_actual_confirmer(): void
    {
        [$venue, $booking, $customer, $confirmer] = $this->confirmedBooking();
        $otherStaff = $this->user('other-staff');
        $this->grantVenuePermission($venue, $otherStaff, VenuePermissionEnum::VIEW_BOOKING_REQUESTS);

        $superadmin = User::factory()->create([
            'username' => 'super-slot-admin',
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $outsider = $this->user('outsider-slot');

        foreach ([null, $outsider, $otherStaff] as $viewer) {
            $response = $viewer === null ? $this->getJson($this->dayUrl($venue))
                : $this->actingAs($viewer)->getJson($this->dayUrl($venue));
            $response->assertOk();
            $slot = $this->bookedSlot($response->json('slots'));
            $this->assertNotNull($slot);
            $this->assertNull($slot['booking_group']['booking_page_url']);
            $this->assertNull($slot['booking_group']['requester_username']);
            $this->assertStringNotContainsString($booking->public_id, $response->getContent());
            $this->assertStringNotContainsString($customer->username, $response->getContent());
        }

        $customerResponse = $this->actingAs($customer)->getJson($this->dayUrl($venue))->assertOk();
        $customerSlot = $this->bookedSlot($customerResponse->json('slots'));
        $this->assertSame(route('account.venue-bookings.show', $booking), $customerSlot['booking_group']['booking_page_url']);
        $this->assertNull($customerSlot['booking_group']['requester_username']);

        foreach ([$superadmin, $confirmer] as $viewer) {
            $response = $this->actingAs($viewer)->getJson($this->dayUrl($venue))->assertOk();
            $slot = $this->bookedSlot($response->json('slots'));
            $this->assertSame(route('account.venue-bookings.show', $booking), $slot['booking_group']['booking_page_url']);
            $this->assertSame($customer->username, $slot['booking_group']['requester_username']);
        }

        // Navigation must remain authorized on the destination, not only in the popup.
        $this->actingAs($confirmer)->get(route('account.venue-bookings.show', $booking))->assertOk();
        $this->actingAs($outsider)->get(route('account.venue-bookings.show', $booking))->assertForbidden();
    }

    public function test_confirmer_loses_the_slot_link_when_the_booking_permission_is_revoked(): void
    {
        [$venue, $booking, , $confirmer, $contract] = $this->confirmedBooking();
        $response = $this->actingAs($confirmer)->getJson($this->dayUrl($venue))->assertOk();
        $this->assertNotNull($this->bookedSlot($response->json('slots'))['booking_group']['booking_page_url']);

        $contract->permissions()->delete();

        $response = $this->actingAs($confirmer)->getJson($this->dayUrl($venue))->assertOk();
        $this->assertNull($this->bookedSlot($response->json('slots'))['booking_group']['booking_page_url']);
        $this->actingAs($confirmer)->get(route('account.venue-bookings.show', $booking))->assertForbidden();
    }

    public function test_unconfirmed_booking_never_exposes_navigation_or_customer_login(): void
    {
        [$venue, $booking, $customer, $confirmer] = $this->confirmedBooking();
        $booking->applyLifecycleTransition(['status' => VenueBookingStatusEnum::HELD]);
        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        foreach ([$customer, $confirmer, $superadmin] as $viewer) {
            $response = $this->actingAs($viewer)->getJson($this->dayUrl($venue))->assertOk();
            $slot = $this->bookedSlot($response->json('slots'));
            $this->assertNotNull($slot);
            $this->assertNull($slot['booking_group']['booking_page_url']);
            $this->assertNull($slot['booking_group']['requester_username']);
        }
    }

    /** @return array{Venue, VenueBooking, User, User, Contract} */
    private function confirmedBooking(): array
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $court = $venue->primaryCourt()->firstOrFail();
        $customer = $this->user('customer-slot');
        $confirmer = $this->user('confirmer-slot');
        $contract = $this->grantVenuePermission($venue, $confirmer, VenuePermissionEnum::CONFIRM_BOOKING_REQUESTS);
        $customerActor = app(CurrentActorResolver::class)->resolve($customer, null);
        $confirmerActor = app(CurrentActorResolver::class)->resolve($confirmer, null);
        $start = CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Moscow');
        $booking = VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'created_by_actor_id' => $customerActor->id,
            'requester_user_id' => $customer->id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'optimistic_version' => 2,
            'requested_at' => now(),
            'confirmed_at' => now(),
            'quote_snapshot' => ['pricing' => ['amount_minor' => 0, 'currency' => 'RUB']],
        ]);
        VenueBookingTransition::query()->create([
            'venue_booking_id' => $booking->id,
            'from_status' => VenueBookingStatusEnum::HELD,
            'to_status' => VenueBookingStatusEnum::CONFIRMED,
            'actor_id' => $confirmerActor->id,
            'booking_version' => 2,
            'created_at' => now(),
        ]);

        return [$venue, $booking, $customer, $confirmer, $contract];
    }

    private function user(string $username): User
    {
        return User::factory()->create([
            'username' => $username,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    private function grantVenuePermission(Venue $venue, User $user, VenuePermissionEnum $permission): Contract
    {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now()->subMinute(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => 'responsible',
        ]);
        $contract->permissions()->create(['permission' => $permission->value]);

        return $contract;
    }

    private function dayUrl(Venue $venue): string
    {
        return route('venues.schedule.day', [
            'venue' => $venue->id,
            'date' => '2026-11-18',
            'venue_court_id' => $venue->primaryCourt()->firstOrFail()->id,
        ]);
    }

    /** @param array<int, array<string, mixed>> $slots */
    private function bookedSlot(array $slots): ?array
    {
        return collect($slots)->first(fn (array $slot): bool =>
            $slot['status'] === 'booked' && data_get($slot, 'booking_group.key') !== null);
    }
}
