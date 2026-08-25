<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking as LegacyVenueBooking;
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
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ExpireVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Events\VenueBookingHeld;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as EventFacade;
use LogicException;
use Tests\TestCase;

final class VenueBookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        Cache::forget('metrics:venue_booking:conflicts');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_request_is_separate_from_event_and_same_quote_is_idempotent(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $quoteId = $this->quote($venue, $owner, $applicant, requiresPayment: false);
        $handler = app(RequestVenueBookingHandler::class);

        $booking = $handler->handle($applicantActor, $quoteId);
        $repeated = $handler->handle($applicantActor, $quoteId);

        $this->assertTrue($booking->is($repeated));
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->status);
        $this->assertFalse($booking->status->occupiesVenue());
        $this->assertTrue(VenueBookingStatusEnum::PENDING->occupiesVenue());
        $this->assertNull($booking->event_id);
        $this->assertDatabaseCount('venue_bookings', 1);
        $this->assertDatabaseCount('venue_booking_transitions', 1);
        $this->assertDatabaseHas('venue_booking_parties', [
            'venue_booking_id' => $booking->id,
            'user_id' => $applicant->id,
            'role' => 'applicant',
        ]);
    }

    public function test_free_booking_follows_requested_held_confirmed_history(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->request($venue, $owner, $applicant, $applicantActor, false);

        $held = app(AcceptVenueBookingHandler::class)->handle($booking->id, $ownerActor, 1);
        $confirmed = app(ConfirmVenueBookingHandler::class)->handle($booking->id, $ownerActor, 2);

        $this->assertSame(VenueBookingStatusEnum::HELD, $held->status);
        $this->assertTrue($held->status->occupiesVenue());
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $confirmed->status);
        $this->assertSame(3, $confirmed->optimistic_version);
        $this->assertNull($confirmed->event_id);
        $this->assertSame(
            ['requested', 'held', 'confirmed'],
            $confirmed->transitions->pluck('to_status')->map->value->all(),
        );
    }

    public function test_paid_booking_cannot_be_confirmed_without_confirmed_payment(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->request($venue, $owner, $applicant, $applicantActor, true);
        app(AcceptVenueBookingHandler::class)->handle($booking->id, $ownerActor);

        try {
            app(ConfirmVenueBookingHandler::class)->handle($booking->id, $ownerActor);
            $this->fail('Paid booking must not confirm before payment.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('PAYMENT_NOT_CONFIRMED', $exception->errorCode);
        }

        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->refresh()->status);
        $this->assertDatabaseCount('venue_booking_transitions', 2);
    }

    public function test_reject_cancel_expire_are_terminal_and_do_not_append_invalid_history(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();

        [$firstApplicant, $firstActor] = $this->userAndActor();
        $rejected = $this->request($venue, $owner, $firstApplicant, $firstActor, false);
        app(RejectVenueBookingHandler::class)->handle($rejected->id, $ownerActor, 'Нет доступа');
        $this->assertInvalidTransitionLeavesHistory($rejected, fn () => app(AcceptVenueBookingHandler::class)->handle($rejected->id, $ownerActor));

        [$secondApplicant, $secondActor] = $this->userAndActor();
        $cancelled = $this->request($venue, $owner, $secondApplicant, $secondActor, false, '2026-08-26 15:00:00');
        app(CancelVenueBookingHandler::class)->handle($cancelled->id, $secondActor, 'Передумал');
        $this->assertInvalidTransitionLeavesHistory($cancelled, fn () => app(RejectVenueBookingHandler::class)->handle($cancelled->id, $ownerActor));

        [$thirdApplicant, $thirdActor] = $this->userAndActor();
        $expired = $this->request($venue, $owner, $thirdApplicant, $thirdActor, false, '2026-08-26 18:00:00');
        app(AcceptVenueBookingHandler::class)->handle($expired->id, $ownerActor);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:31:00', 'Europe/Moscow'));
        app(ExpireVenueBookingHandler::class)->handle($expired->id, app(CurrentActorResolver::class)->system());

        $this->assertSame(VenueBookingStatusEnum::EXPIRED, $expired->refresh()->status);
        $this->assertFalse($expired->status->occupiesVenue());
    }

    public function test_stale_optimistic_version_and_unauthorized_decision_are_rejected(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        [, $strangerActor] = $this->userAndActor();
        $booking = $this->request($venue, $owner, $applicant, $applicantActor, false);

        try {
            app(AcceptVenueBookingHandler::class)->handle($booking->id, $strangerActor);
            $this->fail('Stranger must not decide booking.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_FORBIDDEN', $exception->errorCode);
        }

        app(AcceptVenueBookingHandler::class)->handle($booking->id, $ownerActor, 1);

        try {
            app(ConfirmVenueBookingHandler::class)->handle($booking->id, $ownerActor, 1);
            $this->fail('Stale version must be rejected.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_VERSION_CONFLICT', $exception->errorCode);
        }

        $this->assertDatabaseCount('venue_booking_transitions', 2);
    }

    public function test_status_api_returns_server_actions_and_blocks_idor(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        [$stranger] = $this->userAndActor();
        $booking = $this->request($venue, $owner, $applicant, $applicantActor, false);

        $this->actingAs($applicant)
            ->getJson(route('account.venue-bookings.show', $booking))
            ->assertOk()
            ->assertJsonPath('status', 'requested')
            ->assertJsonPath('event_id', null)
            ->assertJsonPath('actions.accept.allowed', false)
            ->assertJsonPath('actions.accept.reason', 'BOOKING_FORBIDDEN')
            ->assertJsonPath('actions.cancel.allowed', true);

        $this->actingAs($owner)
            ->getJson(route('account.venue-bookings.show', $booking))
            ->assertOk()
            ->assertJsonPath('actions.accept.allowed', true)
            ->assertJsonPath('actions.reject.allowed', true)
            ->assertJsonPath('actions.confirm.reason', 'STATUS_NOT_HELD');

        $this->actingAs($stranger)
            ->getJson(route('account.venue-bookings.show', $booking))
            ->assertForbidden();
    }

    public function test_http_request_and_decision_ignore_client_status(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [$applicant] = $this->userAndActor();
        $quoteId = $this->quote($venue, $owner, $applicant, false);

        $response = $this->actingAs($applicant)->postJson(route('account.venue-bookings.store'), [
            'quote_id' => $quoteId,
            'status' => 'confirmed',
            'event_id' => 999,
        ])->assertCreated()->assertJsonPath('status', 'requested');
        $booking = VenueBooking::query()->where('public_id', $response->json('booking_id'))->firstOrFail();

        $this->actingAs($owner)->postJson(route('account.venue-bookings.accept', $booking), [
            'version' => 1,
            'status' => 'confirmed',
        ])->assertOk()
            ->assertJsonPath('status', 'held')
            ->assertJsonPath('version', 2);

        $this->assertNull($booking->refresh()->event_id);
    }

    public function test_status_cannot_be_updated_outside_lifecycle(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->request($venue, $owner, $applicant, $applicantActor, false);

        $this->expectException(LogicException::class);
        $booking->update(['status' => VenueBookingStatusEnum::CONFIRMED]);
    }

    public function test_booking_http_routes_are_hidden_while_feature_is_disabled(): void
    {
        [$user] = $this->userAndActor();
        config()->set('features.venue_rental.rental_flow', false);

        $this->actingAs($user)->postJson(route('account.venue-bookings.store'), [
            'quote_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertNotFound()
            ->assertJsonPath('code', 'feature_disabled');
    }

    public function test_whole_and_halves_use_half_open_conflict_geometry(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$firstApplicant, $firstActor] = $this->userAndActor();
        [$secondApplicant, $secondActor] = $this->userAndActor();
        [$wholeApplicant, $wholeActor] = $this->userAndActor();
        $halfA = $this->request($venue, $owner, $firstApplicant, $firstActor, false, '2026-08-26 12:00:00', VenueBookingScopeEnum::HALF_A);
        $halfB = $this->request($venue, $owner, $secondApplicant, $secondActor, false, '2026-08-26 12:00:00', VenueBookingScopeEnum::HALF_B);
        $whole = $this->request($venue, $owner, $wholeApplicant, $wholeActor, false, '2026-08-26 12:00:00');

        app(AcceptVenueBookingHandler::class)->handle($halfA->id, $ownerActor);
        app(AcceptVenueBookingHandler::class)->handle($halfB->id, $ownerActor);
        EventFacade::fake([VenueBookingHeld::class]);

        $this->actingAs($owner)
            ->postJson(route('account.venue-bookings.accept', $whole), ['version' => 1])
            ->assertConflict()
            ->assertJsonPath('code', 'BOOKING_CONFLICT')
            ->assertJsonPath('suggested_starts_at.0', '2026-08-26T10:00:00+00:00');
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $whole->refresh()->status);
        $this->assertSame(1, $whole->transitions()->count());
        $this->assertSame(1, Cache::get('metrics:venue_booking:conflicts'));
        EventFacade::assertNotDispatched(VenueBookingHeld::class);

        [$thirdApplicant, $thirdActor] = $this->userAndActor();
        $adjacent = $this->request($venue, $owner, $thirdApplicant, $thirdActor, false, '2026-08-26 13:00:00', VenueBookingScopeEnum::HALF_A);
        app(AcceptVenueBookingHandler::class)->handle($adjacent->id, $ownerActor);
        $this->assertSame(VenueBookingStatusEnum::HELD, $adjacent->refresh()->status);
    }

    public function test_same_half_and_legacy_pending_block_hold(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$firstApplicant, $firstActor] = $this->userAndActor();
        [$secondApplicant, $secondActor] = $this->userAndActor();
        $first = $this->request($venue, $owner, $firstApplicant, $firstActor, false, '2026-08-26 15:00:00', VenueBookingScopeEnum::HALF_A);
        $second = $this->request($venue, $owner, $secondApplicant, $secondActor, false, '2026-08-26 15:30:00', VenueBookingScopeEnum::HALF_A);
        app(AcceptVenueBookingHandler::class)->handle($first->id, $ownerActor);

        $this->expectConflict(fn () => app(AcceptVenueBookingHandler::class)->handle($second->id, $ownerActor));

        [$thirdApplicant, $thirdActor] = $this->userAndActor();
        $rental = $this->request($venue, $owner, $thirdApplicant, $thirdActor, false, '2026-08-26 18:00:00');
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'organizer_actor_id' => $ownerActor->id,
            'starts_at' => CarbonImmutable::parse('2026-08-26 18:00:00', 'Europe/Moscow'),
            'ends_at' => CarbonImmutable::parse('2026-08-26 19:00:00', 'Europe/Moscow'),
        ]);
        LegacyVenueBooking::query()->create([
            'venue_id' => $venue->id,
            'event_id' => $event->id,
            'created_by_actor_id' => $ownerActor->id,
            'status' => VenueBookingStatusEnum::PENDING,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
        ]);

        $this->expectConflict(fn () => app(AcceptVenueBookingHandler::class)->handle($rental->id, $ownerActor));
    }

    public function test_half_is_revalidated_when_hold_is_acquired(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->request($venue, $owner, $applicant, $applicantActor, false, '2026-08-26 12:00:00', VenueBookingScopeEnum::HALF_A);
        $venue->characteristics()->update(['hoops_count' => 1]);

        try {
            app(AcceptVenueBookingHandler::class)->handle($booking->id, $ownerActor);
            $this->fail('Half hold must revalidate physical venue zones.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_SCOPE_UNAVAILABLE', $exception->errorCode);
        }

        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->refresh()->status);
    }

    private function assertInvalidTransitionLeavesHistory(VenueBooking $booking, callable $command): void
    {
        $before = $booking->transitions()->count();

        try {
            $command();
            $this->fail('Terminal booking must reject another transition.');
        } catch (VenueBookingTransitionException) {
            $this->assertSame($before, $booking->transitions()->count());
        }
    }

    private function request(
        Venue $venue,
        User $owner,
        User $applicant,
        Actor $applicantActor,
        bool $requiresPayment,
        string $start = '2026-08-26 12:00:00',
        VenueBookingScopeEnum $scope = VenueBookingScopeEnum::WHOLE,
    ): VenueBooking {
        return app(RequestVenueBookingHandler::class)->handle(
            $applicantActor,
            $this->quote($venue, $owner, $applicant, $requiresPayment, $start, $scope),
        );
    }

    private function quote(
        Venue $venue,
        User $owner,
        User $applicant,
        bool $requiresPayment,
        string $start = '2026-08-26 12:00:00',
        VenueBookingScopeEnum $scope = VenueBookingScopeEnum::WHOLE,
    ): string {
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData($requiresPayment));
        $quote = app(QuoteVenueBookingHandler::class)->handle(
            $venue,
            CarbonImmutable::parse($start, 'Europe/Moscow'),
            60,
            $scope,
            $applicant,
        );

        return $quote->publicId;
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
        $actor = app(CurrentActorResolver::class)->resolve($user, null);

        return [$user, $actor];
    }

    /** @return array<string, mixed> */
    private function policyData(bool $requiresPayment): array
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
            'whole_price_per_step_minor' => $requiresPayment ? 500 : 0,
            'half_price_per_step_minor' => $requiresPayment ? 300 : 0,
            'hold_duration_minutes' => 30,
            'requires_payment' => $requiresPayment,
            'payment_window_minutes' => $requiresPayment ? 30 : null,
            'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ];
    }

    private function expectConflict(callable $command): void
    {
        try {
            $command();
            $this->fail('Overlapping protected booking must be rejected.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_CONFLICT', $exception->errorCode);
        }
    }
}
