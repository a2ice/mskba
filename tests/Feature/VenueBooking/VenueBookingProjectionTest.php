<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Queries\ListOwnerBookingInbox;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueBookingProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.portal', true);
        Carbon::setTestNow('2026-08-26 10:00:00 Europe/Moscow');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_public_availability_contains_busy_intervals_without_personal_or_financial_data(): void
    {
        [$user, $actor] = $this->userAndActor();
        $booking = $this->booking($this->venue(), $user, $actor, VenueBookingStatusEnum::HELD);

        $response = $this->getJson(route('venues.rental.availability', [
            $booking->venue_id, 'from' => now()->toIso8601String(), 'to' => now()->addDays(2)->toIso8601String(),
        ]))->assertOk()->assertJsonCount(1, 'busy')->assertJsonPath('busy.0.version', 1);

        $json = $response->getContent();
        $this->assertStringNotContainsString($user->username, $json);
        $this->assertStringNotContainsString('amount_minor', $json);
        $this->assertStringNotContainsString($booking->public_id, $json);
    }

    public function test_details_timeline_and_stale_command_return_versioned_authorized_contracts(): void
    {
        [$requester, $requesterActor] = $this->userAndActor();
        $booking = $this->booking($this->venue(), $requester, $requesterActor, VenueBookingStatusEnum::REQUESTED);
        VenueBookingTransition::query()->create([
            'venue_booking_id' => $booking->id, 'to_status' => VenueBookingStatusEnum::REQUESTED,
            'actor_id' => $requesterActor->id, 'booking_version' => 1, 'created_at' => now(),
        ]);

        $this->actingAs($requester)->getJson(route('account.venue-bookings.show', $booking))
            ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('primary_action', 'cancel')
            ->assertJsonPath('actions.cancel.allowed', true);
        $this->actingAs($requester)->getJson(route('account.venue-bookings.timeline', $booking))
            ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('data.0.version', 1);

        [$outsider] = $this->userAndActor();
        $this->actingAs($outsider)->getJson(route('account.venue-bookings.timeline', $booking))->assertForbidden();

        $superadmin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $this->actingAs($superadmin)->postJson(route('account.venue-bookings.accept', $booking), [
            'version' => 0, 'idempotency_key' => (string) Str::uuid(),
        ])->assertConflict()
            ->assertJsonPath('code', 'BOOKING_VERSION_CONFLICT')
            ->assertJsonPath('current_state.version', 1)
            ->assertJsonPath('current_state.actions.accept.allowed', true);
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->refresh()->status);
    }

    public function test_owner_inbox_is_paginated_and_has_constant_query_count(): void
    {
        $superadmin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $actor = app(CurrentActorResolver::class)->resolve($superadmin, null);
        [$requester, $requesterActor] = $this->userAndActor();
        $venue = $this->venue();
        foreach (range(1, 25) as $offset) {
            $this->booking($venue, $requester, $requesterActor, VenueBookingStatusEnum::REQUESTED, CarbonImmutable::now()->addDays($offset));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $projection = app(ListOwnerBookingInbox::class)->handle($actor, page: 1, perPage: 10);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(10, $projection['data']);
        $this->assertSame(25, $projection['meta']['total']);
        $this->assertSame(3, $projection['meta']['last_page']);
        $this->assertLessThanOrEqual(7, $queryCount);
        $this->assertSame('accept', $projection['data'][0]['primary_action']);
    }

    private function booking(Venue $venue, User $user, Actor $actor, VenueBookingStatusEnum $status, ?CarbonImmutable $startsAt = null): VenueBooking
    {
        $startsAt ??= now()->addDay();

        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id, 'requester_user_id' => $user->id,
            'quote_snapshot' => ['pricing' => ['amount_minor' => 12500, 'currency' => 'RUB']],
            'status' => $status, 'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_STARTED, 'optimistic_version' => 1,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(), 'requested_at' => now(),
            'hold_expires_at' => $status === VenueBookingStatusEnum::HELD ? now()->addHour() : null,
            'effective_protection_until' => $status === VenueBookingStatusEnum::HELD ? now()->addHour() : null,
        ]);
    }

    private function venue(): Venue
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'operational_status' => VenueOperationalStatusEnum::ACTIVE]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);

        return $venue;
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'username' => 'projection-'.Str::lower(Str::random(12))]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }
}
