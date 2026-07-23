<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
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

    public function test_selected_event_type_is_used_by_create_action_and_form(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-22 12:00:00', 'Europe/Moscow'));
        $user = User::factory()->create();
        Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);

        $this->actingAs($user)
            ->get(route('events.index', ['type' => EventTypeEnum::GAME_TRAINING->value]))
            ->assertOk()
            ->assertSee('Создать игровую тренировку')
            ->assertSee(route('events.create', ['type' => EventTypeEnum::GAME_TRAINING->value]), false);

        $this->actingAs($user)
            ->get(route('events.create', ['type' => EventTypeEnum::GAME_TRAINING->value]))
            ->assertOk()
            ->assertSee('Новая игровая тренировка')
            ->assertSee('Выберите площадку и свободное время.')
            ->assertDontSee('Без расписания площадка доступна круглосуточно.')
            ->assertSee('value="game_training" data-title-prefix="Игровая тренировка" selected', false)
            ->assertSee('Игровая тренировка - 20260722')
            ->assertSee('value="2026-07-22T12:15"', false)
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
        $minimumStart = CarbonImmutable::parse('2026-07-22 12:15:00', 'Europe/Moscow');

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), $this->payload($venue, $minimumStart->subMinute(), $minimumStart->addHour()))
            ->assertRedirect(route('events.create'))
            ->assertSessionHasErrors(['starts_at' => 'Начало должно быть не раньше чем через 15 минут.']);

        $this->actingAs($user)
            ->from(route('events.create'))
            ->post(route('events.store'), array_merge(
                $this->payload($venue, $minimumStart, $minimumStart->addHour()),
                ['duration_minutes' => 45],
            ))
            ->assertRedirect(route('events.create'))
            ->assertSessionHasErrors(['duration_minutes' => 'Выберите длительность от 30 минут до 8 часов с шагом 30 минут.']);

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
            ->assertSee($venue->name);

        $this->actingAs($user)->post(route('events.store'), $payload)->assertRedirect();

        $event = $venue->events()->firstOrFail();
        $this->assertSame(EventTypeEnum::GAME_TRAINING, $event->type);
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);
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
            $newStart->utc()->format('Y-m-d H:i'),
            (string) $event->getRawOriginal('starts_at'),
        );
        $this->assertStringStartsWith(
            $newStart->addHour()->utc()->format('Y-m-d H:i'),
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
            $start->utc()->format('Y-m-d H:i'),
            (string) $event->getRawOriginal('starts_at'),
        );
        $this->assertStringStartsWith(
            $start->utc()->format('Y-m-d H:i'),
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

    public function test_ended_event_is_listed_as_past_and_can_be_completed_later(): void
    {
        $organizer = User::factory()->create();
        [$venue, $start, $end] = $this->availableVenue();
        $this->actingAs($organizer)->post(route('events.store'), $this->payload($venue, $start, $end));
        $event = $venue->events()->firstOrFail();
        $this->travelTo($end->addMinute());

        $this->actingAs($organizer)
            ->get(route('events.index', ['period' => 'past']))
            ->assertOk()
            ->assertSee('Прошедшие мероприятия')
            ->assertSee('Вечерняя игра')
            ->assertSee('Итог не указан');

        $this->actingAs($organizer)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Отметить состоявшимся');

        $this->actingAs($organizer)
            ->put(route('events.result.update', $event->routeIdentifier()), [
                'result_description' => 'Итог добавлен позже.',
            ])
            ->assertSessionHas('status');

        $this->actingAs($organizer)
            ->get(route('events.index', ['period' => 'past']))
            ->assertOk()
            ->assertSee('Состоялось')
            ->assertDontSee('Итог не указан');
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
            'duration_minutes' => (int) $start->diffInMinutes($end),
            'max_participants' => $capacity,
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Вечерняя игра',
            'type' => EventTypeEnum::GAME->value,
            'visibility' => 'public',
            'description' => 'Собираемся играть в баскетбол.',
            'max_participants' => 10,
        ], $overrides);
    }
}
