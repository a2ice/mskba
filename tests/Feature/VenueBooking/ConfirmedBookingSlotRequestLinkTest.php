<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\PublicVenueDaySchedule;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConfirmedBookingSlotRequestLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-17 09:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_booking_customer_superadmin_and_confirming_representative_get_a_request_link(): void
    {
        $client = User::factory()->create([
            'username' => 'booking_customer_demo',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $representative = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $representativeActor = $this->actor($representative);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $court = $venue->primaryCourt()->firstOrFail();
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $this->grantOwnerPermissions($venue, $representative);

        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDay()->setTime(10, 0);
        $booking = VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'created_by_actor_id' => $this->actor($client)->id,
            'requester_user_id' => $client->id,
            'quote_snapshot' => ['policy' => ['version' => 1]],
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED,
            'optimistic_version' => 2,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'confirmed_at' => CarbonImmutable::now('Europe/Moscow'),
        ]);
        $booking->transitions()->create([
            'from_status' => VenueBookingStatusEnum::HELD,
            'to_status' => VenueBookingStatusEnum::CONFIRMED,
            'actor_id' => $representativeActor->id,
            'booking_version' => 2,
            'metadata' => [],
        ]);

        $link = route('account.venue-bookings.show', $booking);
        $clientGroup = $this->slotGroup($venue, $court, $startsAt, $client);
        $this->assertSame($link, $clientGroup['request_url']);
        $this->assertNull($clientGroup['requester_login']);

        $staffGroup = $this->slotGroup($venue, $court, $startsAt, $representative);
        $this->assertSame($link, $staffGroup['request_url']);
        $this->assertSame($client->username, $staffGroup['requester_login']);

        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $adminGroup = $this->slotGroup($venue, $court, $startsAt, $superadmin);
        $this->assertSame($link, $adminGroup['request_url']);
        $this->assertSame($client->username, $adminGroup['requester_login']);

        $outsider = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $outsiderGroup = $this->slotGroup($venue, $court, $startsAt, $outsider);
        $this->assertNull($outsiderGroup['request_url']);
        $this->assertNull($outsiderGroup['requester_login']);

        $anonymousGroup = $this->slotGroup($venue, $court, $startsAt);
        $this->assertNull($anonymousGroup['request_url']);
        $this->assertNull($anonymousGroup['requester_login']);
    }

    /** @return array<string, mixed> */
    private function slotGroup(
        Venue $venue,
        VenueCourt $court,
        CarbonImmutable $startsAt,
        ?User $viewer = null,
    ): array {
        $data = app(PublicVenueDaySchedule::class)->forDate(
            $venue, $court, $startsAt->toDateString(), null, null,
            $viewer, $viewer === null ? null : $this->actor($viewer),
        );
        $slot = collect($data['slots'])->first(
            static fn (array $slot): bool => $slot['starts_at'] === '10:00',
        );
        $this->assertNotNull($slot);
        $this->assertSame('booked', $slot['status']);
        $this->assertNotNull($slot['booking_group']);

        return $slot['booking_group'];
    }

    private function actor(User $user): Actor
    {
        return app(CurrentActorResolver::class)->resolve($user, null);
    }

    private function grantOwnerPermissions(Venue $venue, User $owner): void
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
            'user_id' => $owner->id,
            'access_level' => VenueMembershipAccessLevelEnum::OWNER,
        ]);
        $contract->permissions()->createMany(array_map(
            static fn (VenuePermissionEnum $permission): array => ['permission' => $permission->value],
            VenueMembershipAccessLevelEnum::OWNER->defaultPermissions(),
        ));
    }
}
