<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\GetContributionSummaryHandler;
use App\Modules\VenueBooking\Application\UseCases\SetContributionCommitmentHandler;
use App\Modules\VenueBooking\Application\UseCases\WithdrawContributionCommitmentHandler;
use App\Modules\VenueBooking\Domain\Enums\BookingContributionStatus;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\BookingContributionCommitment;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BookingContributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.contributions', true);
        CarbonImmutable::setTestNow('2026-08-26 10:00:00 Europe/Moscow');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_amount_is_parsed_to_minor_units_and_replacement_preserves_history_without_becoming_payment(): void
    {
        [$organizer, $actor] = $this->userAndActor();
        $booking = $this->booking($organizer, $actor);
        $set = app(SetContributionCommitmentHandler::class);

        $first = $set->handle($booking->id, $actor, '12,34', false);
        $repeat = $set->handle($booking->id, $actor, '12.34', false);
        $replacement = $set->handle($booking->id, $actor, '100.00', true);

        $this->assertSame(1234, $first->amount_minor);
        $this->assertSame($first->id, $repeat->id);
        $this->assertNotSame($first->id, $replacement->id);
        $this->assertDatabaseCount('booking_contribution_commitments', 2);
        $this->assertSame(BookingContributionStatus::REPLACED, $first->refresh()->status);
        $this->assertNull($first->active_marker);
        $this->assertSame(VenueBookingPaymentState::NOT_STARTED, $booking->refresh()->payment_state);

        $summary = app(GetContributionSummaryHandler::class)->handle($booking, $actor);
        $this->assertSame(10000, $summary['committed_minor']);
        $this->assertSame(0, $summary['confirmed_minor']);
        $this->assertSame(1, count($summary['visible_commitments']));
    }

    public function test_privacy_exposes_only_own_or_explicitly_shared_details_and_audits_superadmin(): void
    {
        [$organizer, $organizerActor] = $this->userAndActor();
        [$participant, $participantActor] = $this->userAndActor();
        [$privateParticipant, $privateActor] = $this->userAndActor();
        $booking = $this->booking($organizer, $organizerActor);
        $this->coordination($booking, $organizerActor, [$participant, $privateParticipant]);
        $set = app(SetContributionCommitmentHandler::class);
        $set->handle($booking->id, $organizerActor, '10.00', false);
        $set->handle($booking->id, $participantActor, '20.00', true);
        $set->handle($booking->id, $privateActor, '30.00', false);

        $participantSummary = app(GetContributionSummaryHandler::class)->handle($booking, $participantActor);
        $this->assertSame(6000, $participantSummary['committed_minor']);
        $this->assertSame([$participant->id], array_column($participantSummary['visible_commitments'], 'user_id'));

        $organizerSummary = app(GetContributionSummaryHandler::class)->handle($booking, $organizerActor);
        $this->assertEqualsCanonicalizing(
            [$organizer->id, $participant->id],
            array_column($organizerSummary['visible_commitments'], 'user_id'),
        );

        [$outsider] = $this->userAndActor();
        $this->actingAs($outsider)
            ->getJson(route('account.venue-bookings.contributions.show', $booking))
            ->assertForbidden()
            ->assertJsonPath('code', 'CONTRIBUTION_FORBIDDEN')
            ->assertJsonMissing(['amount_minor' => 3000]);

        $superadmin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $superadminActor = app(CurrentActorResolver::class)->resolve($superadmin, null);
        $supportSummary = app(GetContributionSummaryHandler::class)->handle($booking, $superadminActor, 'support.test');
        $this->assertCount(3, $supportSummary['visible_commitments']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $superadminActor->id,
            'auditable_id' => $booking->id,
            'event' => 'contribution_details_viewed_for_support',
        ]);
    }

    public function test_bounds_currency_precision_terminal_state_and_withdrawal_are_enforced(): void
    {
        [$user, $actor] = $this->userAndActor();
        $booking = $this->booking($user, $actor);
        $set = app(SetContributionCommitmentHandler::class);

        foreach (['0', '-1', '125.001', '125.01'] as $invalid) {
            $this->expectCode('INVALID_CONTRIBUTION_AMOUNT', fn () => $set->handle($booking->id, $actor, $invalid, false));
        }

        $commitment = $set->handle($booking->id, $actor, '125.00', false);
        $withdraw = app(WithdrawContributionCommitmentHandler::class);
        $this->assertSame($commitment->id, $withdraw->handle($booking->id, $actor)?->id);
        $this->assertNull($withdraw->handle($booking->id, $actor));
        $this->assertSame(BookingContributionStatus::WITHDRAWN, $commitment->refresh()->status);
        $this->assertDatabaseCount('booking_contribution_commitments', 1);

        $booking->applyLifecycleTransition(['status' => VenueBookingStatusEnum::CANCELLED, 'terminal_at' => now()]);
        $this->expectCode('CONTRIBUTIONS_CLOSED', fn () => $set->handle($booking->id, $actor, '1.00', false));
        $this->assertDatabaseCount('booking_contribution_commitments', 1);

        $jpy = $this->booking($user, $actor, currency: 'JPY', target: 1000);
        $this->expectCode('INVALID_CONTRIBUTION_AMOUNT', fn () => $set->handle($jpy->id, $actor, '1.5', false));
        $this->assertSame(1, $set->handle($jpy->id, $actor, '1', false)->amount_minor);
    }

    public function test_database_constraint_allows_only_one_active_commitment_per_user_and_booking(): void
    {
        [$user, $actor] = $this->userAndActor();
        $booking = $this->booking($user, $actor);
        app(SetContributionCommitmentHandler::class)->handle($booking->id, $actor, '10.00', false);

        $this->expectException(QueryException::class);
        BookingContributionCommitment::query()->create([
            'public_id' => (string) Str::uuid(), 'venue_booking_id' => $booking->id, 'user_id' => $user->id,
            'amount_minor' => 2000, 'currency' => 'RUB', 'status' => BookingContributionStatus::ACTIVE,
            'active_marker' => true, 'share_with_organizer' => false, 'committed_at' => now(),
        ]);
    }

    private function booking(User $user, Actor $actor, string $currency = 'RUB', int $target = 12500): VenueBooking
    {
        $venue = Venue::factory()->create();
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);

        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id, 'requester_user_id' => $user->id,
            'quote_snapshot' => ['pricing' => ['amount_minor' => $target, 'currency' => $currency]],
            'status' => VenueBookingStatusEnum::HELD, 'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_STARTED, 'optimistic_version' => 1,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
            'held_at' => now(), 'hold_expires_at' => now()->addHour(), 'effective_protection_until' => now()->addHour(),
        ]);
    }

    /** @param list<User> $participants */
    private function coordination(VenueBooking $booking, Actor $organizerActor, array $participants): VenueRentalCoordination
    {
        $coordination = VenueRentalCoordination::query()->create([
            'public_id' => (string) Str::uuid(), 'organizer_actor_id' => $organizerActor->id,
            'organizer_user_id' => $organizerActor->user_id, 'venue_id' => $booking->venue_id,
            'venue_booking_id' => $booking->id, 'title' => 'Сбор на аренду',
            'status' => VenueRentalCoordinationStatus::CONVERTED, 'visibility' => 'private',
            'participants_visibility' => 'participants', 'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $booking->starts_at, 'ends_at' => $booking->ends_at, 'converted_at' => now(),
        ]);
        foreach ($participants as $participant) {
            $coordination->participants()->create(['user_id' => $participant->id, 'joined_at' => now()]);
        }

        return $coordination;
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    private function expectCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected {$code}.");
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
