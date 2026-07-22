<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EventWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_published_event_and_confirmed_booking_on_free_venue(): void
    {
        $user = User::factory()->create(['username' => 'organizer']);
        [$venue, $start, $end] = $this->availableVenue();

        $response = $this->actingAs($user)->post(route('events.store'), $this->payload($venue, $start, $end));

        $event = $venue->events()->firstOrFail();
        $response->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);
        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'role' => EventParticipantRoleEnum::ORGANIZER->value,
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Вечерняя игра')
            ->assertSee('Вы организатор');
        $this->get(route('events.index', ['type' => 'game']))
            ->assertOk()
            ->assertSee('Вечерняя игра');
    }

    public function test_booking_that_requires_approval_creates_draft_event(): void
    {
        $user = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue(['requires_booking_approval' => true]);

        $this->actingAs($user)->post(route('events.store'), $this->payload($venue, $start, $end));

        $event = $venue->events()->firstOrFail();
        $this->assertSame(EventStatusEnum::DRAFT, $event->status);
        $this->assertSame(VenueBookingStatusEnum::PENDING, $event->booking->status);
    }

    public function test_overlapping_event_cannot_be_created_but_adjacent_event_can(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($firstUser)->post(route('events.store'), $this->payload($venue, $start, $end));

        $this->actingAs($secondUser)
            ->from(route('events.create'))
            ->post(route('events.store'), $this->payload($venue, $start->addHour(), $end->addHour()))
            ->assertRedirect(route('events.create'))
            ->assertSessionHas('error', 'Выбранное время уже занято другим мероприятием.');

        $this->actingAs($secondUser)
            ->post(route('events.store'), $this->payload($venue, $end, $end->addHour()))
            ->assertRedirect();

        $this->assertCount(2, $venue->events()->get());
    }

    public function test_event_must_fit_venue_working_hours(): void
    {
        $user = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), $this->payload($venue, $start->subHours(4), $end))
            ->assertRedirect(route('events.create'))
            ->assertSessionHas('error', 'Выбранное время не входит в часы работы площадки.');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_users_can_join_and_leave_until_capacity_is_reached(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        $lateUser = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end, 2));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($participant)
            ->post(route('events.join', $event->routeIdentifier()))
            ->assertSessionHas('status');

        $this->actingAs($lateUser)
            ->post(route('events.join', $event->routeIdentifier()))
            ->assertSessionHas('error', 'Все места на мероприятии уже заняты.');

        $this->actingAs($participant)
            ->delete(route('events.leave', $event->routeIdentifier()))
            ->assertSessionHas('status');

        $this->actingAs($lateUser)
            ->post(route('events.join', $event->routeIdentifier()))
            ->assertSessionHas('status');
    }

    public function test_guest_cannot_create_event(): void
    {
        $this->get(route('events.create'))->assertRedirect(route('login'));
    }

    public function test_organizer_cancels_event_and_releases_booking(): void
    {
        $organizer = User::factory()->create();
        $nextOrganizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($organizer)
            ->post(route('events.cancel', $event->routeIdentifier()), ['reason' => 'Не собрали состав'])
            ->assertSessionHas('status');

        $this->assertSame(EventStatusEnum::CANCELLED, $event->refresh()->status);
        $this->assertSame('Не собрали состав', $event->cancellation_reason);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $event->booking->refresh()->status);

        $this->actingAs($nextOrganizer)
            ->post(route('events.store'), $this->payload($venue, $start, $end))
            ->assertRedirect();
        $this->assertCount(2, $venue->events()->get());
    }

    public function test_unrelated_user_cannot_cancel_event_but_confirmed_superadmin_can(): void
    {
        $organizer = User::factory()->create();
        $stranger = User::factory()->create();
        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($stranger)
            ->post(route('events.cancel', $event->routeIdentifier()))
            ->assertSessionHas('error', 'У вас нет права управлять этим мероприятием.');
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->refresh()->status);

        $this->actingAs($superadmin)
            ->post(route('events.cancel', $event->routeIdentifier()))
            ->assertSessionHas('status');
        $this->assertSame(EventStatusEnum::CANCELLED, $event->refresh()->status);
    }

    public function test_organizer_completes_event_adds_result_and_photos(): void
    {
        Storage::fake('public');
        $organizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();
        $this->travelTo($end->addMinute());

        $this->actingAs($organizer)
            ->put(route('events.result.update', $event->routeIdentifier()), [
                'result_description' => 'Игра состоялась, победила команда хозяев.',
            ])
            ->assertSessionHas('status');

        $this->assertSame(EventStatusEnum::COMPLETED, $event->refresh()->status);
        $this->assertSame('Игра состоялась, победила команда хозяев.', $event->result_description);
        $this->assertNotNull($event->completed_at);

        $this->actingAs($organizer)
            ->post(route('events.result.photos.store', $event->routeIdentifier()), [
                'photo' => UploadedFile::fake()->image('result.jpg', 1600, 900),
            ])
            ->assertSessionHas('photo_status');

        $photo = $event->media()->firstOrFail();
        $this->assertSame('event_results', $photo->collection);
        $this->assertSame('image/webp', $photo->mime);
        Storage::disk('public')->assertExists($photo->path);

        $this->get(route('events.index', ['period' => 'past']))
            ->assertOk()
            ->assertSee('Вечерняя игра');
        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Игра состоялась, победила команда хозяев.');
    }

    public function test_future_event_cannot_be_completed(): void
    {
        $organizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($organizer)
            ->put(route('events.result.update', $event->routeIdentifier()), ['result_description' => 'Рано'])
            ->assertSessionHas('error', 'Подвести итог можно после окончания мероприятия.');

        $this->assertSame(EventStatusEnum::PUBLISHED, $event->refresh()->status);
    }

    /** @return array{Venue, CarbonImmutable, CarbonImmutable} */
    private function availableVenue(array $attributes = []): array
    {
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(8)->startOfDay()->setTime(12, 0);
        $end = $start->addHours(2);
        $venue = Venue::factory()->create(array_merge([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ], $attributes));
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => $start->isoWeekday(),
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'sort_order' => 0,
        ]);

        return [$venue, $start, $end];
    }

    /** @return array<string, mixed> */
    private function payload(Venue $venue, CarbonImmutable $start, CarbonImmutable $end, ?int $capacity = 10): array
    {
        return [
            'venue_id' => $venue->id,
            'title' => 'Вечерняя игра',
            'type' => 'game',
            'visibility' => 'public',
            'description' => 'Собираемся играть в баскетбол.',
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'ends_at' => $end->format('Y-m-d\TH:i'),
            'max_participants' => $capacity,
        ];
    }
}
