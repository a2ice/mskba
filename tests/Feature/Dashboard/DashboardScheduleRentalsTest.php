<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Application\Providers\MyRentalRequestsProvider;
use App\Modules\Dashboard\Application\Providers\UpcomingEventsProvider;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardScheduleRentalsTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedUser(): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
    }

    public function test_schedule_uses_current_confirmed_participation_and_excludes_other_events(): void
    {
        $user = $this->confirmedUser();
        $other = $this->confirmedUser();
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $own = Event::factory()->create(['organizer_actor_id' => $actor->id, 'type' => EventTypeEnum::TRAINING]);
        $joined = Event::factory()->create(['type' => EventTypeEnum::TRAINING])->refresh();
        $joined->participants()->create([
            'user_id' => $user->id, 'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'confirmation_version' => $joined->participation_confirmation_version,
        ]);
        $cancelled = Event::factory()->create(['organizer_actor_id' => $actor->id, 'status' => EventStatusEnum::CANCELLED]);
        $private = Event::factory()->create(['visibility' => EventVisibilityEnum::PRIVATE])->refresh();
        $private->participants()->create([
            'user_id' => $user->id, 'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'confirmation_version' => $private->participation_confirmation_version,
        ]);
        $stale = Event::factory()->create(['type' => EventTypeEnum::TRAINING, 'participation_confirmation_version' => 2]);
        $stale->participants()->create([
            'user_id' => $user->id, 'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => EventParticipantStatusEnum::CONFIRMED, 'confirmation_version' => 1,
        ]);
        $provider = app(UpcomingEventsProvider::class);
        $data = $provider->getData($user, []);
        $this->assertSame(2, $data->total);
        $this->assertEqualsCanonicalizing([$own->id, $joined->id], array_column($data->items, 'id'));
        $this->assertNotContains($cancelled->id, array_column($data->items, 'id'));
        $this->assertNotContains($private->id, array_column($data->items, 'id'));
        $this->assertSame(0, $provider->getData($other, [])->total);
        $this->assertCount(1, $provider->getData($user, ['limit' => 1])->items);
    }

    public function test_rental_source_respects_rollout_and_requester_scope(): void
    {
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.portal', true);
        config()->set('features.venue_rental_rollout.mode', 'all');
        $user = $this->confirmedUser();
        $other = $this->confirmedUser();
        $venue = Venue::factory()->create();
        $create = function (User $requester, VenueBookingStatusEnum $status) use ($venue): VenueBooking {
            $actor = app(CurrentActorResolver::class)->resolve($requester, null);

            return VenueBooking::query()->create([
                'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
                'created_by_actor_id' => $actor->id, 'requester_user_id' => $requester->id,
                'quote_snapshot' => ['pricing' => ['amount_minor' => 12500, 'currency' => 'RUB']],
                'status' => $status, 'scope' => VenueBookingScopeEnum::WHOLE,
                'payment_state' => VenueBookingPaymentState::NOT_STARTED, 'optimistic_version' => 1,
                'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'requested_at' => now(),
            ]);
        };
        $own = $create($user, VenueBookingStatusEnum::REQUESTED);
        $create($other, VenueBookingStatusEnum::REQUESTED);
        $create($user, VenueBookingStatusEnum::CANCELLED);
        $provider = app(MyRentalRequestsProvider::class);
        $this->assertTrue($provider->isAvailable($user, []));
        $data = $provider->getData($user, []);
        $this->assertSame(1, $data->total);
        $this->assertSame($own->public_id, $data->items[0]['id']);
        $this->assertSame(0, $data->summary['pre_request_total']);
        $this->assertArrayNotHasKey('quote_snapshot', $data->items[0]);
        config()->set('features.venue_rental_rollout.mode', 'off');
        $this->assertFalse($provider->isAvailable($user, []));
        config()->set('features.venue_rental_rollout.mode', 'all');
        config()->set('features.venue_rental.portal', false);
        $this->assertFalse($provider->isAvailable($user, []));
    }
}
