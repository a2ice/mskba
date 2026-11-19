<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\UseCases\CreateEventHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventDeferredBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_deferred_event_does_not_create_a_booking_and_retries_are_idempotent(): void
    {
        $user = User::factory()->create();
        $actor = Actor::factory()->create([
            'type' => ActorTypeEnum::USER->value,
            'user_id' => $user->id,
        ]);
        $venue = Venue::factory()->create();
        $requestId = (string) Str::uuid();

        $data = [
            'event_request_id' => $requestId,
            'venue_id' => $venue->id,
            'title' => 'Собираем игроков',
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
            'duration_minutes' => 90,
            'defer_booking' => true,
        ];

        $handler = app(CreateEventHandler::class);
        $event = $handler->handle($actor, $data);

        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertTrue($event->booking_deferred);
        $this->assertNull($event->booking);
        $this->assertDatabaseMissing('event_venue_bookings', ['event_id' => $event->id]);

        $retry = $handler->handle($actor, $data);
        $this->assertSame($event->id, $retry->id);
        $this->assertSame(1, \App\Modules\Event\Domain\Models\Event::query()
            ->where('creation_key', $requestId)->count());
    }
}
