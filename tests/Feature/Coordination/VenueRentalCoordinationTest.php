<?php

namespace Tests\Feature\Coordination;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Coordination\Application\UseCases\CloseVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\ConvertVenueRentalCoordinationToBookingHandler;
use App\Modules\Coordination\Application\UseCases\CreateVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\JoinVenueRentalCoordinationHandler;
use App\Modules\Coordination\Application\UseCases\LeaveVenueRentalCoordinationHandler;
use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class VenueRentalCoordinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.coordination', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_coordination_collects_participants_without_occupying_slot_or_creating_event(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [$organizer, $organizerActor] = $this->userAndActor();
        [$participant] = $this->userAndActor();
        $coordination = $this->coordination($venue, $owner, $organizerActor);

        app(JoinVenueRentalCoordinationHandler::class)->handle($coordination->id, $participant);
        app(JoinVenueRentalCoordinationHandler::class)->handle($coordination->id, $participant);

        $this->assertSame(VenueRentalCoordinationStatus::OPEN, $coordination->refresh()->status);
        $this->assertDatabaseCount('venue_rental_coordination_participants', 2);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseCount((new Event)->getTable(), 0);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $organizer->id]);
    }

    public function test_participant_can_leave_and_rejoin_but_closed_coordination_rejects_changes(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [, $organizerActor] = $this->userAndActor();
        [$participant] = $this->userAndActor();
        $coordination = $this->coordination($venue, $owner, $organizerActor);

        app(JoinVenueRentalCoordinationHandler::class)->handle($coordination->id, $participant);
        app(LeaveVenueRentalCoordinationHandler::class)->handle($coordination->id, $participant);
        app(JoinVenueRentalCoordinationHandler::class)->handle($coordination->id, $participant);
        app(CloseVenueRentalCoordinationHandler::class)->handle($coordination->id, $organizerActor);

        $this->assertNull($coordination->participants()->where('user_id', $participant->id)->firstOrFail()->left_at);
        $this->expectException(InvalidArgumentException::class);
        app(JoinVenueRentalCoordinationHandler::class)->handle($coordination->id, $this->userAndActor()[0]);
    }

    public function test_only_organizer_can_close_or_convert_and_conversion_is_idempotent(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [, $organizerActor] = $this->userAndActor();
        [, $strangerActor] = $this->userAndActor();
        $coordination = $this->coordination($venue, $owner, $organizerActor);

        try {
            app(CloseVenueRentalCoordinationHandler::class)->handle($coordination->id, $strangerActor);
            $this->fail('A stranger must not close a coordination.');
        } catch (InvalidArgumentException) {
            $this->assertSame(VenueRentalCoordinationStatus::OPEN, $coordination->refresh()->status);
        }

        $handler = app(ConvertVenueRentalCoordinationToBookingHandler::class);
        $booking = $handler->handle($coordination->id, $organizerActor, '10000000-0000-4000-8000-000000000001');
        $replayed = $handler->handle($coordination->id, $organizerActor, '10000000-0000-4000-8000-000000000001');

        $this->assertTrue($booking->is($replayed));
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->status);
        $this->assertFalse($booking->status->occupiesVenue());
        $this->assertNull($booking->event_id);
        $this->assertSame($booking->id, $coordination->refresh()->venue_booking_id);
        $this->assertSame(VenueRentalCoordinationStatus::CONVERTED, $coordination->status);
        $this->assertDatabaseCount('venue_bookings', 1);
        $this->assertDatabaseCount((new Event)->getTable(), 0);
    }

    public function test_conversion_gets_fresh_quote_and_fails_if_slot_was_occupied_after_coordination(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [, $organizerActor] = $this->userAndActor();
        [$otherApplicant, $otherActor] = $this->userAndActor();
        $coordination = $this->coordination($venue, $owner, $organizerActor);
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse('2026-08-26 12:00:00', 'Europe/Moscow'),
            60,
            VenueBookingScopeEnum::WHOLE,
            $otherApplicant,
        );
        $blocking = app(RequestVenueBookingHandler::class)->handle($otherActor, $quote->publicId);
        app(AcceptVenueBookingHandler::class)->handle($blocking->id, $ownerActor);

        try {
            app(ConvertVenueRentalCoordinationToBookingHandler::class)->handle(
                $coordination->id,
                $organizerActor,
                '10000000-0000-4000-8000-000000000002',
            );
            $this->fail('Conversion must recheck current availability.');
        } catch (VenueBookingPolicyException) {
            $this->assertNull($coordination->refresh()->venue_booking_id);
            $this->assertSame(VenueRentalCoordinationStatus::OPEN, $coordination->status);
            $this->assertDatabaseCount('venue_bookings', 1);
        }
    }

    public function test_http_visibility_and_coordination_feature_are_independent(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [$organizer, $organizerActor] = $this->userAndActor();
        [$participant] = $this->userAndActor();
        [$stranger] = $this->userAndActor();
        $coordination = $this->coordination($venue, $owner, $organizerActor, [
            'visibility' => 'private',
            'participants_visibility' => 'organizer',
        ]);
        app(JoinVenueRentalCoordinationHandler::class)->handle($coordination->id, $participant);

        $this->actingAs($stranger)->getJson(route('venue-rental-coordinations.show', $coordination))->assertNotFound();
        $this->actingAs($participant)->getJson(route('venue-rental-coordinations.show', $coordination))
            ->assertOk()->assertJsonPath('participants', null)->assertJsonPath('slot_reserved', false);
        $this->actingAs($organizer)->getJson(route('venue-rental-coordinations.show', $coordination))
            ->assertOk()->assertJsonCount(2, 'participants');

        config()->set('features.venue_rental.coordination', false);
        $this->actingAs($organizer)->getJson(route('venue-rental-coordinations.show', $coordination))
            ->assertNotFound()->assertJsonPath('code', 'feature_disabled');
        $this->assertTrue(config()->boolean('features.venue_rental.rental_flow'));
    }

    private function coordination(Venue $venue, User $owner, Actor $organizerActor, array $overrides = []): VenueRentalCoordination
    {
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData());

        return app(CreateVenueRentalCoordinationHandler::class)->handle($organizerActor, array_replace([
            'venue_id' => $venue->id,
            'title' => 'Игра в четверг',
            'starts_at' => '2026-08-26 12:00:00',
            'duration_minutes' => 60,
            'scope' => VenueBookingScopeEnum::WHOLE->value,
            'visibility' => 'public',
            'participants_visibility' => 'participants',
        ], $overrides));
    }

    /** @return array{User, Actor, Venue} */
    private function ownedVenue(): array
    {
        [$owner, $actor] = $this->userAndActor();
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
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

        return [$owner, $actor, $venue];
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    /** @return array<string, mixed> */
    private function policyData(): array
    {
        return [
            'is_enabled' => true,
            'allows_whole' => true,
            'allows_halves' => true,
            'minimum_duration_minutes' => 60,
            'maximum_duration_minutes' => 180,
            'time_step_minutes' => 30,
            'minimum_lead_time_minutes' => 120,
            'maximum_advance_days' => 90,
            'currency' => 'RUB',
            'whole_price_per_step_minor' => 0,
            'half_price_per_step_minor' => 0,
            'hold_duration_minutes' => 30,
            'requires_payment' => false,
            'payment_window_minutes' => null,
            'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ];
    }
}
