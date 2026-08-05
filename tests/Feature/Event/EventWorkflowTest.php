<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleException;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class EventWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_event_duration_uses_end_of_day_without_schedule(): void
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
        ]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(12, 0)->utc();

        $endsAt = app(VenueEventAvailability::class)->resolveEndsAt($venue, $startsAt);

        $this->assertSame(
            '23:59',
            $endsAt->setTimezone('Europe/Moscow')->format('H:i'),
        );
    }

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
        $this->get(route('events.index', ['type' => EventTypeEnum::GAME_TRAINING->value]))
            ->assertOk()
            ->assertSee('Вечерняя игра')
            ->assertSee('catalog-toolbar events-catalog-filters__toolbar', false)
            ->assertSee('name="q"', false)
            ->assertSee('form="event-catalog-filter-form"', false)
            ->assertSee('catalog-card event-catalog-card', false)
            ->assertSee('catalog-card__title', false)
            ->assertSee('catalog-card__badge event-type-badge', false);
        $this->get(route('events.index', ['q' => 'Вечерняя', 'type' => EventTypeEnum::GAME_TRAINING->value]))
            ->assertOk()
            ->assertSee('Вечерняя игра')
            ->assertDontSee('Мини-игры:');
        $this->get(route('events.index', ['q' => 'несуществующее мероприятие']))
            ->assertOk()
            ->assertSee('Попробуйте изменить условия поиска')
            ->assertSee('Сбросить параметры');

        foreach (range(1, 3) as $number) {
            Event::factory()->create([
                'parent_event_id' => $event->id,
                'venue_id' => $venue->id,
                'organizer_actor_id' => $event->organizer_actor_id,
                'title' => "Мини-игра {$number}",
                'starts_at' => $event->starts_at->addMinutes($number * 10),
                'ends_at' => $event->starts_at->addMinutes(($number + 1) * 10),
            ]);
        }

        $this->get(route('events.index', ['q' => 'Вечерняя']))
            ->assertOk()
            ->assertSee('Мини-игры: 3')
            ->assertSee('Мини-игра 1')
            ->assertSee('Мини-игра 2')
            ->assertDontSee('Мини-игра 3')
            ->assertSee('И еще 1…');
        $slotStart = $event->booking->starts_at->setTimezone('Europe/Moscow');
        $slotEnd = $event->booking->ends_at->setTimezone('Europe/Moscow');
        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Занятые слоты')
            ->assertSee($slotStart->format('d.m.Y H:i').'–'.$slotEnd->format('H:i'))
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

    public function test_participation_actions_are_replaced_after_registration_closes(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($participant)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('event-response__button', false)
            ->assertSee('Думаю')
            ->assertDontSee('event-response-closed', false);

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('event-response__button', false)
            ->assertDontSee('event-response-closed', false);

        $event->forceFill([
            'starts_at' => CarbonImmutable::now()->subHours(2),
            'ends_at' => CarbonImmutable::now()->subHour(),
        ])->save();

        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('event-response-closed', false)
            ->assertSee('Завершено')
            ->assertSee('Итог не указан')
            ->assertDontSee('event-response__button', false);
    }

    public function test_event_location_opens_map_and_summary_uses_compact_participant_count(): void
    {
        config(['integrations.yandex.api_key' => 'test-yandex-key']);

        $organizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $location = Location::factory()->create();
        $venue->update(['location_id' => $location->id]);

        $this->actingAs($organizer)->post(
            route('events.store'),
            $this->payload($venue, $start, $end, 10),
        );

        $event = $venue->events()->firstOrFail();

        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-event-map-open', false)
            ->assertSee('data-event-map', false)
            ->assertSee('data-yandex-map-api-key="test-yandex-key"', false)
            ->assertSee('1/10')
            ->assertDontSee('<span>Свободно</span>', false);
    }

    public function test_tentative_and_declined_participants_are_rendered_in_separate_sections(): void
    {
        $organizer = User::factory()->create(['username' => 'event-organizer']);
        $tentativeUser = User::factory()->create(['username' => 'thinking-player']);
        $declinedUser = User::factory()->create(['username' => 'declined-player']);
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($tentativeUser)->patch(
            route('events.participation', $event->routeIdentifier()),
            ['status' => EventParticipantStatusEnum::TENTATIVE->value],
        );
        $this->actingAs($declinedUser)->patch(
            route('events.participation', $event->routeIdentifier()),
            ['status' => EventParticipantStatusEnum::LEFT->value],
        );

        $this->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Думают (1)')
            ->assertSee('thinking-player')
            ->assertSee('Думает')
            ->assertSee('Не идут (1)')
            ->assertSee('declined-player')
            ->assertSee('Не идёт')
            ->assertSee('data-tooltip-variant="title"', false)
            ->assertSee('data-tooltip-icon', false);
    }

    public function test_full_event_keeps_response_changes_available_without_offering_a_new_place(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        $observer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end, 2));
        $event = $venue->events()->firstOrFail();
        $this->actingAs($participant)->post(route('events.join', $event->routeIdentifier()));

        $this->actingAs($participant)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('value="confirmed"', false)
            ->assertSee('value="tentative"', false)
            ->assertSee('value="left"', false);

        $this->actingAs($observer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('value="confirmed"', false)
            ->assertSee('value="tentative"', false)
            ->assertSee('value="left"', false)
            ->assertSee('--event-response-columns: 2', false);

        $this->actingAs($participant)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::LEFT->value,
            ])
            ->assertSessionHas('status', 'Ответ «Не пойду» сохранён.');
    }

    public function test_selected_event_type_is_used_by_create_action_and_form(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-22 12:00:00', 'Europe/Moscow'));
        $user = User::factory()->create();
        Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)
            ->get(route('events.index', ['type' => EventTypeEnum::GAME_TRAINING->value]))
            ->assertOk()
            ->assertSee('Создать')
            ->assertSee(route('events.create', ['type' => EventTypeEnum::GAME_TRAINING->value]), false);

        $this->actingAs($user)
            ->get(route('events.create', ['type' => EventTypeEnum::GAME_TRAINING->value]))
            ->assertOk()
            ->assertSee('Новая игровая тренировка')
            ->assertSee('Выберите площадку и свободное время.')
            ->assertDontSee('Без расписания площадка доступна круглосуточно.')
            ->assertSee('value="game_training" data-title-prefix="Игровая тренировка" selected', false)
            ->assertSee('Игровая тренировка - 20260722')
            ->assertSee('value="2026-07-22T12:00"', false)
            ->assertSee('min="2026-07-22T11:59"', false)
            ->assertSee('name="duration_minutes"', false)
            ->assertSee('value="60" selected', false)
            ->assertSee('1,5 часа')
            ->assertSee('8 часов')
            ->assertDontSee('name="ends_at"', false)
            ->assertSee('Избранные площадки')
            ->assertSee('Функционал находится в разработке.');
    }

    public function test_tournament_cannot_be_selected_or_created_as_an_event(): void
    {
        $user = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();

        $this->actingAs($user)
            ->get(route('events.create'))
            ->assertOk()
            ->assertDontSee('value="tournament"', false);

        $payload = $this->payload($venue, $start, $end);
        $payload['type'] = 'tournament';

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), $payload)
            ->assertRedirect(route('events.create'))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_event_start_and_duration_have_boundaries_and_end_is_calculated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-22 12:00:00', 'Europe/Moscow'));
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $minimumStart = CarbonImmutable::parse('2026-07-22 11:59:00', 'Europe/Moscow');

        $this->actingAs($user)
            ->get(route('events.create'))
            ->assertOk()
            ->assertSee('value="2026-07-22T12:00"', false)
            ->assertSee('min="2026-07-22T11:59"', false);

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), $this->payload($venue, $minimumStart->subMinute(), $minimumStart->addHour()))
            ->assertRedirect(route('events.create'))
            ->assertSessionHasErrors(['starts_at' => 'Начало не может быть раньше текущего времени.']);

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), array_merge(
                $this->payload($venue, $minimumStart, $minimumStart->addHour()),
                ['duration_minutes' => 1441],
            ))
            ->assertRedirect(route('events.create'))
            ->assertSessionHasErrors(['duration_minutes' => 'Мероприятие должно завершиться в течение суток.']);

        $payload = $this->payload($venue, $minimumStart, $minimumStart->addHour());
        $payload['duration_minutes'] = 90;
        $this->actingAs($user)->post(route('events.store'), $payload)->assertRedirect();

        $event = $venue->events()->firstOrFail();
        $this->assertSame(90, (int) $event->starts_at->diffInMinutes($event->ends_at));
    }

    public function test_event_can_be_created_on_venue_without_schedule(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(5)->startOfDay()->setTime(21, 0);
        $end = $start->addHours(2);
        $payload = $this->payload($venue, $start, $end);
        $payload['type'] = EventTypeEnum::GAME_TRAINING->value;

        $this->actingAs($user)
            ->get(route('events.create'))
            ->assertOk()
            ->assertSee('data-venue-selector', false)
            ->assertSee('data-venue-selector-input', false)
            ->assertSee('data-venue-selector-clear', false)
            ->assertSee('data-venue-map-selector-open', false)
            ->assertSee('data-venue-preview-open', false)
            ->assertSee('Начните вводить название, улицу, метро или тег...');

        $this->actingAs($user)->post(route('events.store'), $payload)->assertRedirect();

        $event = $venue->events()->firstOrFail();
        $this->assertSame(EventTypeEnum::GAME_TRAINING, $event->type);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);
    }

    public function test_event_venue_selector_filters_by_slot_and_invalidates_cached_conditions(): void
    {
        $organizer = User::factory()->create();
        [$available, $start, $end] = $this->availableVenue([
            'name' => 'Арбатская свободная площадка',
            'raw_address' => 'Россия, Москва, улица Арбат, 10',
        ]);
        [$occupied] = $this->availableVenue(['name' => 'Арбатская занятая площадка']);

        $this->actingAs($organizer)
            ->post(route('events.store'), $this->payload($occupied, $start, $end))
            ->assertRedirect();

        $parameters = [
            'query' => 'арбатская',
            'confirmed_only' => '1',
            'operational_status' => VenueOperationalStatusEnum::ACTIVE->value,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => 120,
            'limit' => 200,
        ];

        $this->getJson(route('venues.search', $parameters))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $available->id)
            ->assertJsonPath('venues.0.address', 'Москва, улица Арбат, 10')
            ->assertJsonPath('venues.0.latitude', (float) $available->location->address->latitude)
            ->assertJsonPath('venues.0.preview_url', route('venues.preview', $available->routeIdentifier()))
            ->assertJsonMissing(['id' => $occupied->id]);

        $this->getJson(route('venues.preview', $available->routeIdentifier()))
            ->assertOk()
            ->assertJsonPath('venue.id', $available->id)
            ->assertJsonPath('venue.name', 'Арбатская свободная площадка')
            ->assertJsonPath('venue.url', route('venues.show', $available->routeIdentifier()));

        $this->getJson(route('venues.search', [
            ...$parameters,
            'query' => '',
            'venue_id' => $available->id,
            'limit' => 1,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.id', $available->id);

        $this->getJson(route('venues.search', [
            ...$parameters,
            'query' => '',
            'venue_id' => $occupied->id,
            'limit' => 1,
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'venues');

        $available->update([
            'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED->value,
        ]);

        $this->getJson(route('venues.search', $parameters))
            ->assertOk()
            ->assertJsonCount(0, 'venues');
    }

    public function test_closed_exception_applies_to_venue_without_regular_hours(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(6)->startOfDay()->setTime(12, 0);
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleException::query()->create([
            'venue_schedule_id' => $schedule->id,
            'date' => $start->format('Y-m-d'),
            'is_closed' => true,
        ]);

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), $this->payload($venue, $start, $start->addHours(2)))
            ->assertRedirect(route('events.create'))
            ->assertSessionHas('error', 'В выбранную дату площадка закрыта.');

        $this->assertDatabaseCount('events', 0);
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

    public function test_user_can_switch_between_tentative_declined_and_confirmed_responses(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end, 2));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($participant)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::TENTATIVE->value,
            ])
            ->assertSessionHas('status', 'Ответ «Думаю» сохранён.');

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $participant->id,
            'status' => EventParticipantStatusEnum::TENTATIVE->value,
            'joined_at' => null,
            'left_at' => null,
        ]);

        $this->actingAs($participant)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::CONFIRMED->value,
            ])
            ->assertSessionHas('status', 'Вы присоединились к мероприятию.');

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $participant->id,
            'status' => EventParticipantStatusEnum::CONFIRMED->value,
        ]);

        $this->actingAs($participant)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::LEFT->value,
            ])
            ->assertSessionHas('status', 'Ответ «Не пойду» сохранён.');

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $participant->id,
            'status' => EventParticipantStatusEnum::LEFT->value,
        ]);
    }

    public function test_tentative_response_does_not_consume_last_available_place(): void
    {
        $organizer = User::factory()->create();
        $tentativeUser = User::factory()->create();
        $confirmedUser = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end, 2));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($tentativeUser)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::TENTATIVE->value,
            ])
            ->assertSessionHas('status');

        $this->actingAs($confirmedUser)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::CONFIRMED->value,
            ])
            ->assertSessionHas('status');

        $this->assertSame(
            2,
            $event->participants()
                ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                ->count(),
        );
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

    public function test_organizer_and_superadmin_can_edit_event_details_but_unrelated_user_cannot(): void
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
        $booking = $event->booking()->firstOrFail();
        $originalEventStart = $event->starts_at;
        $originalEventEnd = $event->ends_at;
        $originalBookingStart = $booking->starts_at;
        $originalBookingEnd = $booking->ends_at;

        $this->actingAs($organizer)
            ->get(route('events.edit', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Редактирование мероприятия')
            ->assertSee('name="venue_id"', false)
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="duration_minutes"', false);

        $this->actingAs($stranger)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload())
            ->assertSessionHas('error', 'У вас нет права управлять этим мероприятием.');

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'title' => 'Обновлённая тренировка',
                'description' => 'Новое описание встречи.',
                'type' => EventTypeEnum::GAME_TRAINING->value,
                'max_participants' => 12,
            ]))
            ->assertRedirect();

        $event->refresh();
        $this->assertSame('Обновлённая тренировка', $event->title);
        $this->assertSame('Новое описание встречи.', $event->description);
        $this->assertSame(EventTypeEnum::GAME_TRAINING, $event->type);
        $this->assertSame(12, $event->max_participants);
        $this->assertTrue($event->starts_at->equalTo($originalEventStart));
        $this->assertTrue($event->ends_at->equalTo($originalEventEnd));
        $this->assertTrue($booking->refresh()->starts_at->equalTo($originalBookingStart));
        $this->assertTrue($booking->ends_at->equalTo($originalBookingEnd));

        $this->actingAs($superadmin)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'title' => 'Изменено администратором',
            ]))
            ->assertSessionHas('status', 'Мероприятие обновлено.');

        $this->assertSame('Изменено администратором', $event->refresh()->title);
    }

    public function test_organizer_can_move_event_and_booking_between_free_venues(): void
    {
        $organizer = User::factory()->create();
        [$sourceVenue, $start, $end] = $this->availableVenue();
        [$targetVenue] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($sourceVenue, $start, $end));
        $event = $sourceVenue->events()->firstOrFail();
        $booking = $event->booking()->firstOrFail();
        $newStart = $start->addHours(2);

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'venue_id' => $targetVenue->id,
                'starts_at' => $newStart->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ]))
            ->assertSessionHas('status', 'Мероприятие обновлено.');

        $event->refresh();
        $booking->refresh();
        $this->assertSame($targetVenue->id, $event->venue_id);
        $this->assertSame($targetVenue->id, $booking->venue_id);
        $this->assertStringStartsWith(
            $newStart->format('Y-m-d H:i'),
            (string) $event->getRawOriginal('starts_at'),
        );
        $this->assertStringStartsWith(
            $newStart->addHour()->format('Y-m-d H:i'),
            (string) $event->getRawOriginal('ends_at'),
        );
        $this->assertTrue($booking->starts_at->equalTo($event->starts_at));
        $this->assertTrue($booking->ends_at->equalTo($event->ends_at));
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $booking->status);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
    }

    public function test_current_booking_is_excluded_when_duration_changes_inside_its_interval(): void
    {
        $organizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'venue_id' => $venue->id,
                'starts_at' => $start->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ]))
            ->assertSessionHas('status', 'Мероприятие обновлено.');

        $this->assertSame(
            60,
            (int) $event->refresh()->starts_at->diffInMinutes($event->ends_at),
        );
    }

    public function test_conflicting_transfer_keeps_original_event_and_booking_unchanged(): void
    {
        $organizer = User::factory()->create();
        $otherOrganizer = User::factory()->create();
        [$sourceVenue, $start, $end] = $this->availableVenue();
        [$targetVenue] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($sourceVenue, $start, $end));
        $event = $sourceVenue->events()->firstOrFail();
        $booking = $event->booking()->firstOrFail();
        $occupiedStart = $start->addHour();
        $this->actingAs($otherOrganizer)->post(
            route('events.store'),
            $this->payload($targetVenue, $occupiedStart, $occupiedStart->addHours(2)),
        );

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'venue_id' => $targetVenue->id,
                'starts_at' => $occupiedStart->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ]))
            ->assertSessionHas('error', 'Выбранное время уже занято другим мероприятием.');

        $event->refresh();
        $booking->refresh();
        $this->assertSame($sourceVenue->id, $event->venue_id);
        $this->assertSame($sourceVenue->id, $booking->venue_id);
        $this->assertStringStartsWith(
            $start->format('Y-m-d H:i'),
            (string) $event->getRawOriginal('starts_at'),
        );
        $this->assertStringStartsWith(
            $start->format('Y-m-d H:i'),
            (string) $booking->getRawOriginal('starts_at'),
        );
    }

    public function test_booking_cannot_be_moved_when_current_or_target_venue_is_not_free(): void
    {
        $organizer = User::factory()->create();
        [$managedVenue, $start, $end] = $this->availableVenue(['requires_booking_approval' => true]);
        [$freeVenue] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($managedVenue, $start, $end));
        $event = $managedVenue->events()->firstOrFail();

        $this->actingAs($organizer)
            ->get(route('events.edit', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Для площадок с оплатой или подтверждением бронирования');

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'venue_id' => $freeVenue->id,
                'starts_at' => $start->addHour()->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ]))
            ->assertSessionHas(
                'error',
                'Площадку и время пока можно менять только для мероприятий на свободных площадках.',
            );

        $this->assertSame($managedVenue->id, $event->refresh()->venue_id);
        $this->assertSame($managedVenue->id, $event->booking->refresh()->venue_id);
    }

    public function test_participant_limit_cannot_be_reduced_below_current_participants(): void
    {
        $organizer = User::factory()->create();
        $firstParticipant = User::factory()->create();
        $secondParticipant = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end, 10));
        $event = $venue->events()->firstOrFail();
        $this->actingAs($firstParticipant)->post(route('events.join', $event->routeIdentifier()));
        $this->actingAs($secondParticipant)->post(route('events.join', $event->routeIdentifier()));

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'max_participants' => null,
            ]))
            ->assertSessionHas('status');

        $this->actingAs($organizer)
            ->put(route('events.update', $event->routeIdentifier()), $this->updatePayload([
                'max_participants' => 2,
            ]))
            ->assertSessionHas('error', 'Лимит участников не может быть меньше числа уже записавшихся.');

        $this->assertNull($event->refresh()->max_participants);
    }

    public function test_organizer_completes_event_adds_result_and_photos(): void
    {
        Storage::fake('public');
        $organizer = User::factory()->create();
        $participant = User::factory()->create(['username' => 'tagged_player']);
        $participant->profile()->create(['first_name' => 'Илья', 'last_name' => 'Игроков']);
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();
        $this->actingAs($participant)->post(route('events.join', $event->routeIdentifier()));
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
                'photo' => UploadedFile::fake()->image('result.jpg', 1600, 900)->size(7 * 1024),
            ])
            ->assertSessionHas('photo_status');

        $photo = $event->media()->firstOrFail();
        $this->assertSame('event_results', $photo->collection);
        $this->assertSame('image/webp', $photo->mime);
        Storage::disk('public')->assertExists($photo->path);

        $this->actingAs($organizer)
            ->putJson(route('events.result.photos.update', [$event->routeIdentifier(), $photo->id]), [
                'description' => 'Решающий бросок в концовке.',
                'tags' => [[
                    'user_id' => $participant->id,
                    'x' => 42.75,
                    'y' => 61.5,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('description', 'Решающий бросок в концовке.')
            ->assertJsonPath('tags.0.name', 'Илья Игроков');

        $this->assertDatabaseHas('media', [
            'id' => $photo->id,
            'description' => 'Решающий бросок в концовке.',
        ]);
        $this->assertDatabaseHas('event_result_photo_tags', [
            'media_id' => $photo->id,
            'user_id' => $participant->id,
            'position_x' => 42.75,
            'position_y' => 61.5,
        ]);

        $outsider = User::factory()->create();
        $this->actingAs($organizer)
            ->putJson(route('events.result.photos.update', [$event->routeIdentifier(), $photo->id]), [
                'description' => 'Подмена',
                'tags' => [['user_id' => $outsider->id, 'x' => 10, 'y' => 20]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'На фотографии можно отметить только участников мероприятия.');
        $this->assertSame('Решающий бросок в концовке.', $photo->refresh()->description);

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-event-hero-source="results"', false)
            ->assertSee($photo->publicUrl(), false)
            ->assertSee('Решающий бросок в концовке.')
            ->assertSee('Илья Игроков');

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

    public function test_ended_event_is_listed_as_past_and_can_be_completed_later(): void
    {
        $organizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();
        $this->travelTo($end->addMinute());

        $pastEvents = $this->actingAs($organizer)
            ->get(route('events.index', ['period' => 'past']));
        $pastEvents->assertOk();
        $pastEvents->assertSee('Мероприятия');
        $pastEvents->assertSee('Вечерняя игра');
        $pastEvents->assertSee('Итог не указан');

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Отметить состоявшимся');

        $this->actingAs($organizer)
            ->put(route('events.result.update', $event->routeIdentifier()), [
                'result_description' => 'Итог добавлен позже.',
            ])
            ->assertSessionHas('status');

        $completedEvents = $this->actingAs($organizer)
            ->get(route('events.index', ['period' => 'past']));
        $completedEvents->assertOk();
        $completedEvents->assertSee('Состоялось');
    }

    public function test_event_journals_can_be_filtered_by_type_dates_and_past_outcome(): void
    {
        $organizer = User::factory()->create();
        [$gameVenue, $start, $end] = $this->availableVenue();
        [$trainingVenue] = $this->availableVenue();

        $gamePayload = $this->payload($gameVenue, $start, $end);
        $gamePayload['title'] = 'Игра для фильтра';
        $this->actingAs($organizer)->post(route('events.store'), $gamePayload);
        $game = $gameVenue->events()->firstOrFail();

        $trainingPayload = $this->payload($trainingVenue, $start, $end);
        $trainingPayload['title'] = 'Тренировка для фильтра';
        $trainingPayload['type'] = EventTypeEnum::TRAINING->value;
        $this->actingAs($organizer)->post(route('events.store'), $trainingPayload);
        $training = $trainingVenue->events()->firstOrFail();
        $eventDate = $start->format('Y-m-d');

        $this->actingAs($organizer)
            ->get(route('events.index', [
                'type' => EventTypeEnum::GAME_TRAINING->value,
                'date_from' => $eventDate,
                'date_to' => $eventDate,
            ]))
            ->assertOk()
            ->assertSee('Игра для фильтра')
            ->assertDontSee('Тренировка для фильтра')
            ->assertDontSee('name="outcome"', false);

        $this->actingAs($organizer)
            ->get(route('events.index', ['date_to' => $eventDate]))
            ->assertOk()
            ->assertSee('Игра для фильтра')
            ->assertSee('Тренировка для фильтра');

        $this->travelTo($end->addMinute());
        $this->actingAs($organizer)
            ->put(route('events.result.update', $game->routeIdentifier()), [
                'result_description' => 'Игра состоялась.',
            ])
            ->assertSessionHas('status');

        $this->actingAs($organizer)
            ->get(route('events.index', ['period' => 'past', 'outcome' => 'completed']))
            ->assertOk()
            ->assertSee('Игра для фильтра')
            ->assertDontSee('Тренировка для фильтра')
            ->assertSee('name="outcome"', false);

        $this->actingAs($organizer)
            ->get(route('events.index', ['period' => 'past', 'outcome' => 'unmarked']))
            ->assertOk()
            ->assertDontSee('Игра для фильтра')
            ->assertSee('Тренировка для фильтра')
            ->assertSee('Итог не указан');

        $this->assertSame(EventStatusEnum::PUBLISHED, $training->refresh()->status);
    }

    public function test_organizer_searches_privacy_visible_users_and_adds_participant(): void
    {
        $organizer = User::factory()->create(['username' => 'event-owner']);
        $visibleUser = User::factory()->create(['username' => 'visible-player']);
        $hiddenUser = User::factory()->create(['username' => 'hidden-player']);
        UserPrivacySetting::query()->create([
            'user_id' => $hiddenUser->id,
            'type' => UserPrivacySettingTypeEnum::DISCOVERABILITY,
            'visibility' => UserPrivacyVisibilityEnum::NOBODY,
        ]);
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        $this->actingAs($organizer)
            ->getJson(route('events.participants.candidates', [
                'event' => $event->routeIdentifier(),
                'query' => 'player',
            ]))
            ->assertOk()
            ->assertJsonFragment(['id' => $visibleUser->id, 'username' => 'visible-player'])
            ->assertJsonMissing(['id' => $hiddenUser->id]);

        $this->actingAs($organizer)
            ->post(route('events.participants.manage.store', $event->routeIdentifier()), [
                'user_id' => $visibleUser->id,
            ])
            ->assertSessionHas('status', 'Пользователь добавлен в список «Думают».');

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $visibleUser->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT->value,
            'status' => EventParticipantStatusEnum::TENTATIVE->value,
        ]);
        $participant = $event->participants()->where('user_id', $visibleUser->id)->firstOrFail();
        $this->actingAs($organizer)
            ->patch(route('events.participants.manage.status', [$event->routeIdentifier(), $participant->id]), [
                'status' => EventParticipantStatusEnum::CONFIRMED->value,
            ])
            ->assertSessionHas('status', 'Пользователь отмечен как участник.');
        $this->assertSame(EventParticipantStatusEnum::CONFIRMED, $participant->refresh()->status);
        $this->actingAs($organizer)->patchJson(
            route('events.participants.manage.status', [$event->routeIdentifier(), $participant->id]),
            ['status' => EventParticipantStatusEnum::LEFT->value],
        )->assertOk()->assertJsonPath('participant.status', EventParticipantStatusEnum::LEFT->value);
        $this->actingAs($organizer)->patchJson(
            route('events.participants.manage.status', [$event->routeIdentifier(), $participant->id]),
            ['status' => EventParticipantStatusEnum::TENTATIVE->value],
        )->assertOk()->assertJsonPath('participant.status', EventParticipantStatusEnum::TENTATIVE->value);

        $this->actingAs($organizer)
            ->post(route('events.participants.manage.store', $event->routeIdentifier()), [
                'user_id' => $hiddenUser->id,
            ])
            ->assertSessionHas('error', 'Этот пользователь недоступен для добавления.');
    }

    public function test_organizer_cannot_change_participants_after_event_time_has_ended(): void
    {
        $organizer = User::factory()->create(['username' => 'retrospective-owner']);
        $participant = User::factory()->create(['username' => 'retrospective-player']);
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(
            route('events.store'),
            [
                ...$this->payload($venue, $start, $end),
                'type' => EventTypeEnum::GAME_TRAINING->value,
            ],
        );
        $event = $venue->events()->firstOrFail();

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Для создания мини-игры нужны хотя бы два участника.')
            ->assertSee('href="#event-participant-management"', false);

        $event->forceFill([
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ])->save();

        $this->actingAs($organizer)
            ->postJson(route('events.participants.manage.store', $event->routeIdentifier()), [
                'user_id' => $participant->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Состав этого мероприятия уже нельзя изменять.');

        $this->actingAs($organizer)
            ->getJson(route('events.participants.candidates', [
                'event' => $event->routeIdentifier(),
                'query' => 'retrospective',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Состав этого мероприятия уже нельзя изменять.');

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('data-event-participant-manager', false)
            ->assertDontSee('data-event-participant-status-form', false);

        $this->assertDatabaseMissing('event_participants', [
            'event_id' => $event->id,
            'user_id' => $participant->id,
        ]);
    }

    public function test_participant_management_is_locked_after_event_is_completed(): void
    {
        $organizer = User::factory()->create();
        $candidate = User::factory()->create(['username' => 'late-player']);
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();
        $event->forceFill(['status' => EventStatusEnum::COMPLETED])->save();

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('data-event-participant-manager', false)
            ->assertDontSee('data-event-participant-status-form', false);

        $this->actingAs($organizer)
            ->getJson(route('events.participants.candidates', [
                'event' => $event->routeIdentifier(),
                'query' => 'late',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Состав этого мероприятия уже нельзя изменять.');

        $this->actingAs($organizer)
            ->postJson(route('events.participants.manage.store', $event->routeIdentifier()), [
                'user_id' => $candidate->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Состав этого мероприятия уже нельзя изменять.');
    }

    public function test_multiple_responsible_participants_accept_invitation_and_organizer_can_remove_them(): void
    {
        $organizer = User::factory()->create(['username' => 'event-owner']);
        $first = User::factory()->create(['username' => 'first-helper']);
        $second = User::factory()->create(['username' => 'second-helper']);
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();

        foreach ([$first, $second] as $user) {
            $this->actingAs($organizer)->post(
                route('events.participants.manage.store', $event->routeIdentifier()),
                ['user_id' => $user->id],
            );
        }

        $participants = $event->participants()
            ->whereIn('user_id', [$first->id, $second->id])
            ->get()
            ->keyBy('user_id');

        foreach ($participants as $participant) {
            $this->actingAs($organizer)->patch(route('events.participants.manage.status', [
                $event->routeIdentifier(), $participant->id,
            ]), ['status' => EventParticipantStatusEnum::CONFIRMED->value]);
            $this->actingAs($organizer)
                ->post(route('events.participants.responsibility.request', [
                    $event->routeIdentifier(),
                    $participant->id,
                ]))
                ->assertSessionHas('status', 'Запрос на назначение отправлен участнику.');
        }

        $this->assertDatabaseCount('event_participants', 3);
        $this->assertDatabaseHas('event_participants', [
            'id' => $participants[$first->id]->id,
            'responsibility_status' => EventResponsibilityStatusEnum::PENDING->value,
        ]);

        foreach ([$first, $second] as $user) {
            $participant = $participants[$user->id];
            $this->actingAs($user)
                ->patch(route('events.participants.responsibility.respond', [
                    $event->routeIdentifier(),
                    $participant->id,
                ]), ['decision' => EventResponsibilityStatusEnum::ACCEPTED->value])
                ->assertSessionHas('status', 'Вы подтвердили назначение ответственным.');
        }

        $this->assertSame(
            2,
            $event->participants()
                ->where('responsibility_status', EventResponsibilityStatusEnum::ACCEPTED->value)
                ->count(),
        );

        $this->actingAs($organizer)
            ->delete(route('events.participants.responsibility.destroy', [
                $event->routeIdentifier(),
                $participants[$first->id]->id,
            ]))
            ->assertSessionHas('status', 'Назначение ответственного снято.');

        $this->assertDatabaseHas('event_participants', [
            'id' => $participants[$first->id]->id,
            'responsibility_status' => null,
        ]);
        $this->assertDatabaseHas('event_participants', [
            'id' => $participants[$second->id]->id,
            'responsibility_status' => EventResponsibilityStatusEnum::ACCEPTED->value,
        ]);
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
            'type' => EventTypeEnum::GAME_TRAINING->value,
            'visibility' => 'public',
            'description' => 'Собираемся играть в баскетбол.',
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => (int) $start->diffInMinutes($end),
            'max_participants' => $capacity,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Вечерняя игра',
            'type' => EventTypeEnum::GAME_TRAINING->value,
            'visibility' => 'public',
            'description' => 'Собираемся играть в баскетбол.',
            'max_participants' => 10,
        ], $overrides);
    }
}
