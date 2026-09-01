<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueBookingExpiryDispatcher;
use App\Modules\VenueBooking\Application\UseCases\ExpireVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingOutboxMessage;
use App\Modules\VenueBooking\Infrastructure\Jobs\ExpireVenueBookingIfDueJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueBookingExpirySchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
        foreach (['scheduled', 'completed', 'stale', 'failed'] as $metric) {
            Cache::forget("metrics:venue_booking:expiry:{$metric}");
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dispatcher_selects_due_rental_holds_in_small_batches(): void
    {
        Queue::fake();
        $first = $this->booking(deadline: CarbonImmutable::now()->subMinutes(2));
        $second = $this->booking(deadline: CarbonImmutable::now()->subMinute());
        $this->booking(deadline: CarbonImmutable::now()->subMinutes(3));
        $this->booking(deadline: CarbonImmutable::now()->addMinute());
        $this->booking(deadline: null);
        $this->booking(deadline: CarbonImmutable::now()->subMinute(), status: VenueBookingStatusEnum::REQUESTED);

        $this->assertSame(2, app(VenueBookingExpiryDispatcher::class)->dispatchDue(2));
        Queue::assertPushed(ExpireVenueBookingIfDueJob::class, 2);
        Queue::assertPushed(fn (ExpireVenueBookingIfDueJob $job): bool => in_array($job->bookingId, [$first->id, $second->id], true));
        $this->assertSame(2, Cache::get('metrics:venue_booking:expiry:scheduled'));
    }

    public function test_job_expires_once_and_repeat_worker_is_stale(): void
    {
        Queue::fake();
        $booking = $this->booking(deadline: CarbonImmutable::now()->subSecond());
        $job = $this->job($booking);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertSame(VenueBookingStatusEnum::EXPIRED, $booking->refresh()->status);
        $this->assertSame(1, $booking->transitions()->count());
        $this->assertSame(1, VenueBookingOutboxMessage::query()->where('venue_booking_id', $booking->id)->count());
        $this->assertSame(1, Cache::get('metrics:venue_booking:expiry:completed'));
        $this->assertSame(1, Cache::get('metrics:venue_booking:expiry:stale'));
    }

    public function test_stale_job_does_not_expire_extended_or_confirmed_booking(): void
    {
        Queue::fake();
        $extended = $this->booking(deadline: CarbonImmutable::now()->subMinute());
        $extendedJob = $this->job($extended);
        $extended->update([
            'effective_protection_until' => CarbonImmutable::now()->addMinutes(20),
            'optimistic_version' => $extended->optimistic_version + 1,
        ]);

        app()->call([$extendedJob, 'handle']);
        $this->assertSame(VenueBookingStatusEnum::HELD, $extended->refresh()->status);

        $confirmed = $this->booking(deadline: CarbonImmutable::now()->subMinute());
        $confirmedJob = $this->job($confirmed);
        $confirmed->applyLifecycleTransition([
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'effective_protection_until' => null,
            'confirmed_at' => now(),
            'optimistic_version' => $confirmed->optimistic_version + 1,
        ]);
        app()->call([$confirmedJob, 'handle']);

        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $confirmed->refresh()->status);
        $this->assertSame(2, Cache::get('metrics:venue_booking:expiry:stale'));
        $this->assertDatabaseCount('venue_booking_transitions', 0);
    }

    public function test_deadline_comparison_is_timezone_safe_and_command_is_system_only(): void
    {
        Queue::fake();
        $deadline = CarbonImmutable::parse('2026-08-25 06:59:59', 'UTC');
        $booking = $this->booking(deadline: $deadline);
        app()->call([$this->job($booking), 'handle']);
        $this->assertSame(VenueBookingStatusEnum::EXPIRED, $booking->refresh()->status);

        $another = $this->booking(deadline: CarbonImmutable::now()->subMinute());
        [, $userActor] = $this->userAndActor();
        try {
            app(ExpireVenueBookingHandler::class)->handle($another->id, $userActor);
            $this->fail('User actor must not expire a booking.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_FORBIDDEN', $exception->errorCode);
        }
        $this->assertSame(VenueBookingStatusEnum::HELD, $another->refresh()->status);
    }

    public function test_feature_disabled_dispatcher_is_noop_and_status_api_uses_server_deadline(): void
    {
        Queue::fake();
        $booking = $this->booking(deadline: CarbonImmutable::now()->addMinute());
        config()->set('features.venue_rental.rental_flow', false);
        $this->assertSame(0, app(VenueBookingExpiryDispatcher::class)->dispatchDue());
        Queue::assertNothingPushed();

        config()->set('features.venue_rental.rental_flow', true);
        $requester = User::query()->findOrFail($booking->requester_user_id);
        $this->actingAs($requester)
            ->getJson(route('account.venue-bookings.show', $booking))
            ->assertOk()
            ->assertJsonPath('effective_protection_until', $booking->effective_protection_until->utc()->toIso8601String())
            ->assertJsonStructure(['server_time']);
    }

    private function job(VenueBooking $booking): ExpireVenueBookingIfDueJob
    {
        return new ExpireVenueBookingIfDueJob(
            $booking->id,
            $booking->optimistic_version,
            $booking->effective_protection_until->toIso8601String(),
        );
    }

    private function booking(?CarbonImmutable $deadline, VenueBookingStatusEnum $status = VenueBookingStatusEnum::HELD): VenueBooking
    {
        [$requester, $actor] = $this->userAndActor();
        $venue = Venue::factory()->create();

        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id,
            'requester_user_id' => $requester->id,
            'status' => $status,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED,
            'optimistic_version' => 1,
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
            'hold_expires_at' => $deadline,
            'effective_protection_until' => $deadline,
            'held_at' => $status === VenueBookingStatusEnum::HELD ? CarbonImmutable::now()->subMinutes(30) : null,
        ]);
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }
}
