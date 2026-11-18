<?php

namespace Tests\Feature\PlayInterest;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Application\Listeners\NotifyMatchingEventSubscribers;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventInterestNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        $organizer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = Actor::factory()->create(['type' => ActorTypeEnum::USER->value, 'user_id' => $organizer->id]);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE,
        ]);
        return Event::factory()->create([
            'venue_id' => $venue->id, 'organizer_actor_id' => $actor->id,
            'type' => EventTypeEnum::GAME_TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addDays(3)->setTime(18, 0),
            'ends_at' => now()->addDays(3)->setTime(19, 30),
        ]);
    }

    private function intent(Event $event, User $user, array $overrides = []): PlayIntent
    {
        return PlayIntent::query()->create(array_replace([
            'user_id' => $user->id, 'venue_id' => $event->venue_id,
            'name' => 'Игра рядом', 'include_similar_venues' => false,
            'cadence' => 'flexible', 'status' => 'active',
            'notify_matches' => true, 'weekdays' => [],
            'dates' => [], 'windows' => [], 'formats' => [],
            'game_delivery_channels' => ['in_app'],
        ], $overrides));
    }

    public function test_published_public_event_notifies_matching_player_once_for_multiple_subscriptions(): void
    {
        $event = $this->event();
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->intent($event, $user);
        $this->intent($event, $user, ['name' => 'Дублирующий интерес']);

        $listener = app(NotifyMatchingEventSubscribers::class);
        $listener->handle(new EventChanged($event->id));
        $listener->handle(new EventChanged($event->id));

        $this->assertSame(1, DB::table('event_interest_notification_deliveries')
            ->where('event_id', $event->id)->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('user_notifications')
            ->where('user_id', $user->id)->where('action_url', route('events.show', $event->routeIdentifier(), false))->count());
    }

    public function test_private_or_out_of_schedule_event_does_not_notify(): void
    {
        $event = $this->event();
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->intent($event, $user, ['weekdays' => [8]]);
        app(NotifyMatchingEventSubscribers::class)->handle(new EventChanged($event->id));
        $this->assertSame(0, DB::table('event_interest_notification_deliveries')->count());

        $this->intent($event, $user);
        $event->update(['visibility' => EventVisibilityEnum::PRIVATE]);
        app(NotifyMatchingEventSubscribers::class)->handle(new EventChanged($event->id));
        $this->assertSame(0, DB::table('event_interest_notification_deliveries')->count());
    }

    public function test_event_catalog_exposes_shared_creation_dialog(): void
    {
        $this->get(route('events.index'))->assertOk()
            ->assertSee('data-quick-event-open', false)
            ->assertSee('data-quick-event-dialog', false);
    }
}
