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
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\ApproveVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\RejectVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingExtensionHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingExtensionStatus;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Jobs\ExpireVenueBookingIfDueJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueBookingExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_request_does_not_change_deadline_and_policy_limit_is_enforced(): void
    {
        [, , $venue] = $this->ownedVenue();
        [$applicant, $actor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $actor);

        $extension = app(RequestVenueBookingExtensionHandler::class)->handle(
            $booking->id,
            $actor,
            $booking->effective_protection_until->addMinutes(20),
            'Нужно завершить согласование состава.',
        );

        $this->assertSame(VenueBookingExtensionStatus::PENDING, $extension->status);
        $this->assertTrue($booking->effective_protection_until->equalTo($booking->refresh()->effective_protection_until));
        $this->assertSame(1, $booking->optimistic_version);

        app(CancelVenueBookingExtensionHandler::class)->handle($booking->id, $extension->id, $actor);
        $this->expectCode('EXTENSION_LIMIT_EXCEEDED', fn () => app(RequestVenueBookingExtensionHandler::class)->handle(
            $booking->id,
            $actor,
            $booking->hold_expires_at->addMinutes(31),
            'Слишком долгий срок.',
        ));
    }

    public function test_approve_is_atomic_repeat_safe_and_makes_old_expiry_job_stale(): void
    {
        Queue::fake();
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $applicantActor);
        $oldJob = new ExpireVenueBookingIfDueJob($booking->id, 1, $booking->effective_protection_until->toIso8601String());
        $requestedUntil = $booking->effective_protection_until->addMinutes(20);
        $extension = app(RequestVenueBookingExtensionHandler::class)->handle($booking->id, $applicantActor, $requestedUntil, 'Нужно больше времени.');

        $handler = app(ApproveVenueBookingExtensionHandler::class);
        $handler->handle($booking->id, $extension->id, $ownerActor, 'Согласовано.');
        $handler->handle($booking->id, $extension->id, $ownerActor, 'Повтор доставки команды.');

        $booking->refresh();
        $this->assertTrue($booking->effective_protection_until->equalTo($requestedUntil));
        $this->assertSame(2, $booking->optimistic_version);
        $this->assertSame(VenueBookingExtensionStatus::APPROVED, $extension->refresh()->status);

        CarbonImmutable::setTestNow($extension->previous_deadline_at->addSecond());
        app()->call([$oldJob, 'handle']);
        $this->assertSame(VenueBookingStatusEnum::HELD, $booking->refresh()->status);
    }

    public function test_new_conflict_prevents_approval_without_changing_request_or_deadline(): void
    {
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $applicantActor);
        $extension = app(RequestVenueBookingExtensionHandler::class)->handle(
            $booking->id,
            $applicantActor,
            $booking->effective_protection_until->addMinutes(10),
            'Ожидаем подтверждение.',
        );
        [$other, $otherActor] = $this->userAndActor();
        $this->heldBooking($venue, $other, $otherActor, startsAt: $booking->starts_at->addMinutes(15));

        $this->expectCode('BOOKING_CONFLICT', fn () => app(ApproveVenueBookingExtensionHandler::class)
            ->handle($booking->id, $extension->id, $ownerActor));

        $this->assertSame(VenueBookingExtensionStatus::PENDING, $extension->refresh()->status);
        $this->assertSame(1, $booking->refresh()->optimistic_version);
    }

    public function test_commercial_side_rejects_and_only_requester_cancels(): void
    {
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$applicant, $applicantActor] = $this->userAndActor();
        [, $outsiderActor] = $this->userAndActor();
        $booking = $this->heldBooking($venue, $applicant, $applicantActor);
        $request = app(RequestVenueBookingExtensionHandler::class)->handle($booking->id, $applicantActor, $booking->effective_protection_until->addMinutes(10), 'Причина.');

        $this->expectCode('BOOKING_FORBIDDEN', fn () => app(CancelVenueBookingExtensionHandler::class)->handle($booking->id, $request->id, $outsiderActor));
        app(RejectVenueBookingExtensionHandler::class)->handle($booking->id, $request->id, $ownerActor, 'Не можем продлить.');

        $this->assertSame(VenueBookingExtensionStatus::REJECTED, $request->refresh()->status);
        $this->assertTrue($booking->effective_protection_until->equalTo($booking->refresh()->effective_protection_until));
    }

    private function heldBooking(Venue $venue, User $requester, Actor $actor, ?CarbonImmutable $startsAt = null): VenueBooking
    {
        $startsAt ??= CarbonImmutable::now()->addDay();
        $deadline = CarbonImmutable::now()->addMinutes(30);

        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(),
            'flow' => 'rental',
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id,
            'requester_user_id' => $requester->id,
            'quote_snapshot' => ['policy' => [
                'allows_hold_extension' => true,
                'maximum_hold_extension_minutes' => 30,
                'time_step_minutes' => 30,
            ]],
            'status' => VenueBookingStatusEnum::HELD,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED,
            'optimistic_version' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'hold_expires_at' => $deadline,
            'effective_protection_until' => $deadline,
            'held_at' => now(),
        ]);
    }

    /** @return array{User, Actor, Venue} */
    private function ownedVenue(): array
    {
        [$owner, $actor] = $this->userAndActor();
        $venue = Venue::factory()->create();
        $venue->characteristics()->create(['hoops_count' => 2]);
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
