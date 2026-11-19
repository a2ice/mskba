<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventFirstFunnelTest extends TestCase
{
    use RefreshDatabase;

    private function venue(): Venue
    {
        return Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
        ]);
    }

    private function payload(Venue $venue, array $slots = [], string $mode = 'alternatives'): array
    {
        return [
            'event_request_id' => (string) Str::uuid(),
            'venue_id' => $venue->id,
            'type' => 'training',
            'visibility' => 'public',
            'title' => 'Утренняя тренировка',
            'slots' => $slots,
            'schedule_mode' => $mode,
        ];
    }

    private function slots(): array
    {
        $day = CarbonImmutable::now('Europe/Moscow')->addDays(4);
        return [
            ['starts_at' => $day->setTime(19, 0)->format('Y-m-d\TH:i'), 'duration_minutes' => 90],
            ['starts_at' => $day->addDay()->setTime(18, 30)->format('Y-m-d\TH:i'), 'duration_minutes' => 60],
        ];
    }

    public function test_guest_cannot_create_event_but_can_fill_public_wizard(): void
    {
        $venue = $this->venue();
        $this->postJson(route('events.funnel.store'), $this->payload($venue))
            ->assertUnauthorized();
        $this->assertDatabaseCount('events', 0);
    }

    public function test_without_schedule_creates_only_an_unpublished_unscheduled_draft(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();

        $this->actingAs($user)->postJson(route('events.funnel.store'), $this->payload($venue))
            ->assertCreated()->assertJsonPath('created_count', 1);
        $event = Event::query()->sole();

        $this->assertSame(EventStatusEnum::DRAFT, $event->status);
        $this->assertNull($event->starts_at);
        $this->assertNull($event->ends_at);
        $this->assertTrue($event->booking_deferred);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseCount('event_venue_interests', 0);
        $this->actingAs($user)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee('Мероприятие сохранено как черновик');
    }

    public function test_two_alternative_slots_create_one_event_and_one_nonbinding_interest(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();

        $this->actingAs($user)->postJson(route('events.funnel.store', []),
            $this->payload($venue, $this->slots()))
            ->assertCreated()->assertJsonPath('created_count', 1);

        $event = Event::query()->sole();
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertCount(2, $event->proposed_time_options);
        $this->assertTrue($event->booking_deferred);
        $this->assertDatabaseCount('event_venue_interests', 1);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_separate_mode_creates_distinct_events_without_bookings_and_retries_are_idempotent(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $payload = $this->payload($venue, $this->slots(), 'separate');

        $this->actingAs($user)->postJson(route('events.funnel.store'), $payload)
            ->assertCreated()->assertJsonPath('created_count', 2);
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_venue_interests', 2);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->actingAs($user)->postJson(route('events.funnel.store'), $payload)
            ->assertCreated()->assertJsonPath('created_count', 2);
        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseCount('event_venue_interests', 2);
    }

    public function test_duplicate_time_options_are_rejected(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $slot = $this->slots()[0];

        $this->actingAs($user)->postJson(route('events.funnel.store'),
            $this->payload($venue, [$slot, $slot]))
            ->assertUnprocessable()->assertJsonValidationErrors('slots');
        $this->assertDatabaseCount('events', 0);
    }
}
