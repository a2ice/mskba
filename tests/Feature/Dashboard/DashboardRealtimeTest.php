<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardWidgetsInvalidatedBroadcast;
use App\Modules\Dashboard\Infrastructure\Listeners\InvalidateChangedEventWidgets;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event as PortalEvent;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class DashboardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_changes_signal_only_owner_after_commit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $venue = Venue::factory()->create();
        Event::fake([DashboardWidgetsInvalidatedBroadcast::class]);

        DB::transaction(function () use ($user, $venue): void {
            VenueSubscription::query()->create(['user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active']);
            Event::assertNotDispatched(DashboardWidgetsInvalidatedBroadcast::class);
        });

        Event::assertDispatched(DashboardWidgetsInvalidatedBroadcast::class, fn ($event) =>
            $event->broadcastOn()->name === 'private-users.'.$user->id
            && $event->broadcastWith() === ['sources' => ['my_interests'], 'catalog_changed' => false]);
        Event::assertNotDispatched(DashboardWidgetsInvalidatedBroadcast::class, fn ($event) =>
            $event->broadcastOn()->name === 'private-users.'.$other->id);
    }

    public function test_rollback_does_not_signal_owner(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create();
        Event::fake([DashboardWidgetsInvalidatedBroadcast::class]);

        DB::beginTransaction();
        VenueSubscription::query()->create(['user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active']);
        DB::rollBack();

        Event::assertNotDispatched(DashboardWidgetsInvalidatedBroadcast::class);
    }

    public function test_public_event_invalidates_interests_but_private_event_does_not(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create();
        VenueSubscription::query()->create(['user_id' => $user->id, 'venue_id' => $venue->id, 'status' => 'active']);
        $public = PortalEvent::factory()->create(['venue_id' => $venue->id, 'type' => EventTypeEnum::TRAINING]);
        $private = PortalEvent::factory()->create(['venue_id' => $venue->id, 'visibility' => EventVisibilityEnum::PRIVATE]);
        $listener = app(InvalidateChangedEventWidgets::class);
        Event::fake([DashboardWidgetsInvalidatedBroadcast::class]);

        $listener->handle(new EventChanged($private->id));
        Event::assertNotDispatched(DashboardWidgetsInvalidatedBroadcast::class, fn ($event) =>
            $event->broadcastOn()->name === 'private-users.'.$user->id
            && in_array('my_interests', $event->broadcastWith()['sources'], true));

        $listener->handle(new EventChanged($public->id));
        Event::assertDispatched(DashboardWidgetsInvalidatedBroadcast::class, fn ($event) =>
            $event->broadcastOn()->name === 'private-users.'.$user->id
            && $event->broadcastWith() === ['sources' => ['my_interests'], 'catalog_changed' => false]);
    }
}
