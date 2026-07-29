<?php

namespace Tests\Feature\Coordination;

use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\CoordinationSessionStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\Models\PollOption;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CoordinationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_create_button_opens_auth_flow_with_create_page_redirect(): void
    {
        $this->get(route('coordination.index'))
            ->assertOk()
            ->assertSee('Создать опрос')
            ->assertSee('data-modal-target="auth-entry-classic"', false)
            ->assertSee('data-auth-redirect-url="'.route('coordination.create', [], false).'"', false);
    }

    public function test_create_form_defaults_to_attendance_and_explains_all_scenarios(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('coordination.create'))
            ->assertOk()
            ->assertSeeInOrder([
                'Собрать участников',
                'Простой опрос',
                'Выбрать время',
                'Выбрать площадку',
                'Дата, время и площадка',
            ])
            ->assertSee('value="event_attendance" selected', false)
            ->assertSee('Описание сценариев опроса')
            ->assertSee('Простой опрос — произвольный вопрос и варианты ответа.');
    }

    public function test_active_user_can_create_poll_and_blocked_user_cannot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('coordination.store'), $this->payload());

        $session = CoordinationSession::query()->firstOrFail();
        $response->assertRedirect(route('coordination.show', $session));
        $this->assertSame(CoordinationSessionStatusEnum::OPEN, $session->status);
        $this->assertSame(PollStatusEnum::OPEN, $session->polls()->firstOrFail()->status);
        $this->assertFalse($session->polls()->firstOrFail()->allows_vote_changes);
        $this->assertFalse($session->polls()->firstOrFail()->is_anonymous);
        $this->assertFalse($session->polls()->firstOrFail()->allows_suggestions);
        $this->assertDatabaseCount('coordination_poll_options', 3);
        $this->get(route('coordination.index'))->assertOk()->assertSee('Игра вечером');
        $this->get(route('coordination.show', $session))->assertOk()->assertSee('Во сколько играем?');
        $this->travelTo(CarbonImmutable::parse('2026-07-25 12:34:00'));
        $this->actingAs($user)
            ->get(route('coordination.create'))
            ->assertOk()
            ->assertSee('Разрешить менять голос')
            ->assertSee('Анонимный опрос')
            ->assertSee('Разрешить свои варианты')
            ->assertDontSee('name="allows_vote_changes" value="1" checked', false)
            ->assertDontSee('name="is_anonymous" value="1" checked', false)
            ->assertSee('Тип вариантов')
            ->assertSee('Интервал времени')
            ->assertSee('Площадка')
            ->assertSee('Начните вводить название, улицу, метро или тег')
            ->assertSee('value="2026-07-25T13:34"', false);
        $this->travelBack();

        $blocked = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        $this->actingAs($blocked)
            ->post(route('coordination.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_poll_form_lists_only_available_event_venues(): void
    {
        $user = User::factory()->create();
        $availableVenue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'name' => 'Доступная площадка',
        ]);
        Venue::factory()->create([
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'name' => 'Неподтверждённая площадка',
        ]);

        $this->actingAs($user)
            ->get(route('coordination.create'))
            ->assertOk()
            ->assertSee($availableVenue->name)
            ->assertDontSee('Неподтверждённая площадка')
            ->assertDontSee('Нет доступных площадок');
    }

    public function test_attendance_poll_can_resolve_duration_until_next_booking(): void
    {
        $organizer = User::factory()->create();
        [$venue, $startsAt] = $this->availableVenue();
        $this->assertTrue(
            $startsAt->addHours(6)->equalTo(
                app(VenueEventAvailability::class)->resolveEndsAt($venue, $startsAt),
            ),
        );
        $event = Event::factory()->for($venue)->create([
            'starts_at' => $startsAt->addHours(2),
            'ends_at' => $startsAt->addHours(3),
        ]);
        VenueBooking::query()->create([
            'venue_id' => $venue->id,
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'starts_at' => $startsAt->addHours(2),
            'ends_at' => $startsAt->addHours(3),
        ]);

        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                'flow_type' => CoordinationFlowTypeEnum::EVENT_ATTENDANCE->value,
                'title' => 'Автоматическая длительность',
                'fixed_venue_id' => $venue->id,
                'fixed_starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'event_duration_minutes' => '',
                'going_label' => 'Пойду',
                'not_going_label' => 'Не пойду',
                'include_thinking_option' => '0',
                'results_visibility' => 'after_vote',
                'allows_vote_changes' => '0',
                'is_anonymous' => '0',
                'allows_suggestions' => '0',
                'publish_to_telegram' => '0',
                'closes_at' => CarbonImmutable::now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $poll = CoordinationSession::query()->latest('id')->firstOrFail()->polls()->firstOrFail();

        $this->assertTrue($poll->configuration['automatic_duration']);
        $this->assertSame(120, $poll->configuration['duration_minutes']);
    }

    public function test_typed_poll_options_are_normalized_for_web_and_telegram_labels(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'name' => 'Школа №1794',
        ]);
        $secondVenue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'name' => 'Около Дегунино',
        ]);
        $cases = [
            'date' => [
                'options' => ['2026-08-01', '2026-08-02'],
                'labels' => ['01.08.2026', '02.08.2026'],
                'values' => [['date' => '2026-08-01'], ['date' => '2026-08-02']],
            ],
            'time' => [
                'options' => ['19:00', '20:30'],
                'labels' => ['19:00', '20:30'],
                'values' => [['time' => '19:00'], ['time' => '20:30']],
            ],
            'datetime' => [
                'options' => ['2026-08-01T19:00', '2026-08-02T20:30'],
                'labels' => ['01.08.2026 19:00', '02.08.2026 20:30'],
                'values' => [
                    ['datetime' => '2026-08-01T19:00'],
                    ['datetime' => '2026-08-02T20:30'],
                ],
            ],
            'time_interval' => [
                'options' => [
                    ['starts_at' => '19:00', 'ends_at' => '20:00'],
                    ['starts_at' => '20:00', 'ends_at' => '21:30'],
                ],
                'labels' => ['19:00–20:00', '20:00–21:30'],
                'values' => [
                    ['starts_at' => '19:00', 'ends_at' => '20:00'],
                    ['starts_at' => '20:00', 'ends_at' => '21:30'],
                ],
            ],
            'venue' => [
                'options' => [$venue->id, $secondVenue->id],
                'labels' => ['Школа №1794', 'Около Дегунино'],
                'values' => [['venue_id' => $venue->id], ['venue_id' => $secondVenue->id]],
            ],
        ];

        foreach ($cases as $subjectType => $case) {
            $this->actingAs($user)->post(route('coordination.store'), [
                ...$this->payload(),
                'title' => 'Опрос '.$subjectType,
                'subject_type' => $subjectType,
                'options' => $case['options'],
            ])->assertRedirect();

            $poll = CoordinationSession::query()->latest('id')->firstOrFail()->polls()->firstOrFail();
            $this->assertSame($subjectType, $poll->subject_type->value);
            $this->assertSame($case['labels'], $poll->options()->pluck('label')->all());
            $this->assertSame($case['values'], $poll->options()->get()->pluck('value')->all());
        }
    }

    public function test_event_scheduling_chain_activates_steps_and_filters_occupied_venues(): void
    {
        Queue::fake();
        $organizer = User::factory()->create();
        $otherOrganizer = User::factory()->create();
        [$availableVenue, $startsAt] = $this->availableVenue();
        [$occupiedVenue] = $this->availableVenue();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);

        $this->actingAs($otherOrganizer)
            ->post(route('events.store'), $this->eventPayload($occupiedVenue, $startsAt))
            ->assertRedirect();

        $this->actingAs($organizer)
            ->post(route('coordination.store'), array_replace(
                $this->chainPayload([$availableVenue, $occupiedVenue], $startsAt),
                [
                    'publish_to_telegram' => '1',
                    'telegram_chat_ids' => [$chat->id],
                ],
            ))
            ->assertRedirect();

        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $polls = $session->polls()->with('options')->get();
        $this->assertSame(CoordinationFlowTypeEnum::EVENT_SCHEDULING, $session->flow_type);
        $this->assertSame(
            [PollStatusEnum::OPEN, PollStatusEnum::DRAFT, PollStatusEnum::DRAFT],
            $polls->pluck('status')->all(),
        );

        $this->actingAs($organizer)->post(route('coordination.close', $session));
        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), [
                'option_id' => $polls[0]->options[0]->id,
            ])
            ->assertSessionHas('status');

        $polls = $session->fresh()->polls()->with('options')->get();
        $this->assertSame(PollStatusEnum::OPEN, $polls[1]->status);
        $this->assertSame(PollStatusEnum::DRAFT, $polls[2]->status);
        $this->assertDatabaseHas('telegram_coordination_publications', [
            'poll_id' => $polls[1]->id,
            'chat_id' => $chat->id,
            'status' => 'pending',
        ]);
        $this->actingAs($organizer)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertDontSee('Создать мероприятие');

        $this->actingAs($organizer)->post(route('coordination.close', $session));
        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), [
                'option_id' => $polls[1]->options[0]->id,
            ])
            ->assertSessionHas('status');

        $venuePoll = $session->fresh()->polls()->with('options')->get()[2];
        $this->assertSame(PollStatusEnum::OPEN, $venuePoll->status);
        $this->assertSame(
            [$availableVenue->id],
            $venuePoll->options
                ->where('is_active', true)
                ->pluck('value')
                ->map(fn (array $value): int => (int) $value['venue_id'])
                ->values()
                ->all(),
        );

        $this->actingAs($organizer)->post(route('coordination.close', $session));
        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), [
                'option_id' => $venuePoll->options->firstWhere('is_active', true)->id,
            ])
            ->assertSessionHas('status');

        $session->refresh();
        $this->assertSame(CoordinationSessionStatusEnum::COMPLETED, $session->status);
        $this->assertDatabaseCount('coordination_decisions', 3);
        $this->actingAs($organizer)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Создать мероприятие')
            ->assertSee($startsAt->format('Y-m-d\TH:i'), false)
            ->assertSee('value="'.$availableVenue->id.'"', false);
    }

    public function test_attendance_poll_keeps_semantic_intents_and_allows_manual_participant_selection(): void
    {
        $organizer = User::factory()->create(['username' => 'poll_organizer']);
        $organizer->profile()->create([
            'first_name' => 'Дмитрий',
            'last_name' => 'Организатор',
        ]);
        $goingUser = User::factory()->create();
        $customUser = User::factory()->create();
        [$venue, $startsAt] = $this->availableVenue();
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Тестовая улица',
            'building' => '10',
            'full_address' => 'Россия, Москва, Тестовая улица, 10',
            'latitude' => 55.751244,
            'longitude' => 37.618423,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $venue->update([
            'name' => 'Площадка для опроса',
            'raw_address' => 'Россия, Москва, Тестовая улица, 10',
            'location_id' => $location->id,
        ]);

        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                'flow_type' => CoordinationFlowTypeEnum::EVENT_ATTENDANCE->value,
                'title' => 'Собираем состав',
                'description' => 'Проверяем, кто сможет прийти.',
                'fixed_venue_id' => $venue->id,
                'fixed_starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'event_duration_minutes' => 60,
                'going_label' => 'Я в игре',
                'not_going_label' => 'Пропущу',
                'include_thinking_option' => '1',
                'thinking_label' => 'Уточню позже',
                'results_visibility' => 'after_vote',
                'allows_vote_changes' => '0',
                'is_anonymous' => '0',
                'allows_suggestions' => '1',
                'publish_to_telegram' => '0',
                'closes_at' => CarbonImmutable::now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $poll = $session->polls()->with('options')->firstOrFail();
        $pollClosesAtLabel = $poll->closes_at
            ->setTimezone('Europe/Moscow')
            ->locale('ru')
            ->translatedFormat('j F H:i');
        $this->assertSame(CoordinationFlowTypeEnum::EVENT_ATTENDANCE, $session->flow_type);
        $this->assertSame(
            ['going', 'not_going', 'thinking'],
            $poll->options->pluck('value')->map(fn (array $value): string => $value['intent'])->all(),
        );
        $this->assertSame(['Я в игре', 'Пропущу', 'Уточню позже'], $poll->options->pluck('label')->all());
        $this->actingAs($organizer)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Создал опрос')
            ->assertSee('Дмитрий Организатор')
            ->assertSee('Площадка для опроса')
            ->assertSee('Москва, Тестовая улица, 10')
            ->assertDontSee('Россия, Москва, Тестовая улица, 10')
            ->assertSee(
                $startsAt->locale('ru')->translatedFormat('j F H:i').'–'.$startsAt->addHour()->format('H:i'),
            )
            ->assertSee('до '.$pollClosesAtLabel)
            ->assertSee('data-modal-target="coordination-venue-map"', false)
            ->assertSee('data-event-map-open', false);

        $this->actingAs($goingUser)->post(route('coordination.vote', $session), [
            'option_ids' => [$poll->options[0]->id],
        ])->assertSessionHas('status');
        $this->actingAs($customUser)->post(route('coordination.suggestion', $session), [
            'option' => 'Опоздаю на 15 минут',
        ])->assertSessionHas('status');
        $customOption = $poll->fresh()->options()->where('proposed_by_user_id', $customUser->id)->firstOrFail();
        $this->assertSame('custom', $customOption->value['intent']);
        $this->actingAs($customUser)->post(route('coordination.vote', $session), [
            'option_ids' => [$customOption->id],
        ])->assertSessionHas('status');

        $this->actingAs($organizer)->post(route('coordination.close', $session));
        $this->actingAs($organizer)->post(route('coordination.decision', $session), [
            'option_id' => $poll->options[0]->id,
        ])->assertSessionHas('status');

        $this->actingAs($organizer)
            ->post(route('coordination.event.store', $session), [
                ...$this->eventPayload($venue, $startsAt),
                'participant_user_ids' => [$goingUser->id, $customUser->id],
            ])
            ->assertRedirect();

        $event = $venue->events()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $goingUser->id,
            'status' => EventParticipantStatusEnum::CONFIRMED->value,
        ]);
        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $customUser->id,
            'status' => EventParticipantStatusEnum::CONFIRMED->value,
        ]);
    }

    public function test_time_poll_uses_start_times_and_rechecks_user_suggestions(): void
    {
        Queue::fake();
        $organizer = User::factory()->create();
        $otherOrganizer = User::factory()->create();
        $participant = User::factory()->create();
        [$venue, $startsAt] = $this->availableVenue();

        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                'flow_type' => CoordinationFlowTypeEnum::EVENT_TIME_SELECTION->value,
                'title' => 'Выбираем время сбора',
                'fixed_venue_id' => $venue->id,
                'fixed_date' => $startsAt->format('Y-m-d'),
                'event_duration_minutes' => 60,
                'start_time_options' => ['12:00', '13:00'],
                'results_visibility' => 'after_vote',
                'allows_vote_changes' => '0',
                'is_anonymous' => '0',
                'allows_suggestions' => '1',
                'publish_to_telegram' => '0',
                'closes_at' => CarbonImmutable::now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $poll = $session->polls()->with('options')->firstOrFail();
        $this->assertSame(CoordinationFlowTypeEnum::EVENT_TIME_SELECTION, $session->flow_type);
        $this->assertSame([['time' => '12:00'], ['time' => '13:00']], $poll->options->pluck('value')->all());
        $this->assertSame($venue->id, $poll->configuration['venue_id']);
        $this->assertSame($startsAt->format('Y-m-d'), $poll->configuration['date']);

        $this->actingAs($otherOrganizer)
            ->post(route('events.store'), $this->eventPayload($venue, $startsAt->setTime(14, 0)))
            ->assertRedirect();

        $this->actingAs($participant)
            ->post(route('coordination.suggestion', $session), ['option' => '14:00'])
            ->assertSessionHas('error', 'Выбранное время уже занято другим мероприятием.');

        $this->assertDatabaseMissing('coordination_poll_options', [
            'poll_id' => $poll->id,
            'label' => '14:00',
        ]);
    }

    public function test_accepted_event_change_moves_booking_and_requires_participant_reconfirmation(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        [$sourceVenue, $startsAt] = $this->availableVenue();
        [$targetVenue] = $this->availableVenue();

        $this->actingAs($organizer)
            ->post(route('events.store'), $this->eventPayload($sourceVenue, $startsAt))
            ->assertRedirect();
        $event = $sourceVenue->events()->firstOrFail();
        $this->actingAs($participant)
            ->post(route('events.join', $event->routeIdentifier()))
            ->assertSessionHas('status');

        $newStartsAt = $startsAt->addHours(2);
        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                ...$this->chainPayload([$sourceVenue, $targetVenue], $newStartsAt),
                'context_event_id' => $event->id,
            ])
            ->assertRedirect();

        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $this->assertSame(CoordinationFlowTypeEnum::EVENT_CHANGE, $session->flow_type);
        $eventTimeLabel = $event->starts_at->setTimezone('Europe/Moscow')->locale('ru')->translatedFormat('j F H:i')
            .'–'.$event->ends_at->setTimezone('Europe/Moscow')->format('H:i');
        $this->actingAs($organizer)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee($sourceVenue->name)
            ->assertSee($eventTimeLabel);
        $polls = $session->polls()->with('options')->get();

        foreach ([0, 1, 2] as $index) {
            $this->actingAs($organizer)->post(route('coordination.close', $session));
            $option = $index === 2
                ? $polls[$index]->options->first(
                    fn (PollOption $item): bool => (int) $item->value['venue_id'] === $targetVenue->id,
                )
                : $polls[$index]->options[0];
            $this->actingAs($organizer)
                ->post(route('coordination.decision', $session), ['option_id' => $option->id])
                ->assertSessionHas('status');
            $polls = $session->fresh()->polls()->with('options')->get();
        }

        $this->actingAs($organizer)
            ->post(route('coordination.event-change.apply', $session))
            ->assertRedirect();

        $event->refresh();
        $this->assertSame($targetVenue->id, $event->venue_id);
        $this->assertSame(2, $event->participation_confirmation_version);
        $this->assertSame(
            1,
            $event->participants()->where('user_id', $participant->id)->value('confirmation_version'),
        );
        $this->actingAs($participant)
            ->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()
            ->assertSee('Время или площадка изменились. Подтвердите участие повторно.');
        $this->actingAs($participant)
            ->patch(route('events.participation', $event->routeIdentifier()), [
                'status' => EventParticipantStatusEnum::CONFIRMED->value,
            ])
            ->assertSessionHas('status');
        $this->assertSame(
            2,
            $event->participants()->where('user_id', $participant->id)->value('confirmation_version'),
        );
    }

    public function test_typed_option_invariants_reject_invalid_interval_and_unavailable_venue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('coordination.store'), [
                ...$this->payload(),
                'subject_type' => 'time_interval',
                'options' => [
                    ['starts_at' => '20:00', 'ends_at' => '19:00'],
                    ['starts_at' => '21:00', 'ends_at' => '22:00'],
                ],
            ])
            ->assertSessionHas('error', 'Окончание интервала должно быть позже начала.');

        $venue = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        $this->actingAs($user)
            ->post(route('coordination.store'), [
                ...$this->payload(),
                'subject_type' => 'venue',
                'options' => [$venue->id, Venue::factory()->create()->id],
            ])
            ->assertSessionHas('error', 'Выбранная площадка недоступна.');

        $this->assertDatabaseCount('coordination_sessions', 0);
    }

    public function test_participant_can_suggest_typed_unique_option_when_creator_allows_it(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'subject_type' => 'date',
            'options' => ['2026-08-01', '2026-08-02'],
            'allows_suggestions' => '1',
        ])->assertRedirect();
        $session = CoordinationSession::query()->latest('id')->firstOrFail();

        $this->actingAs($participant)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Предложить вариант');
        $this->actingAs($participant)
            ->post(route('coordination.suggestion', $session), ['option' => '2026-08-03'])
            ->assertSessionHas('status', 'Вариант добавлен.');

        $suggested = PollOption::query()->where('poll_id', $session->polls()->firstOrFail()->id)
            ->where('proposed_by_user_id', $participant->id)
            ->firstOrFail();
        $this->assertSame('03.08.2026', $suggested->label);
        $this->assertSame(['date' => '2026-08-03'], $suggested->value);

        $this->actingAs($participant)
            ->post(route('coordination.suggestion', $session), ['option' => '2026-08-03'])
            ->assertSessionHas('error', 'Такой вариант уже есть в опросе.');
        $this->assertDatabaseCount('coordination_poll_options', 3);
    }

    public function test_suggestion_is_refused_when_creator_did_not_allow_it(): void
    {
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        $session = $this->createSession($organizer);

        $this->actingAs($participant)
            ->post(route('coordination.suggestion', $session), ['option' => '22:00'])
            ->assertSessionHas('error', 'В этом опросе нельзя предлагать варианты.');

        $this->assertDatabaseCount('coordination_poll_options', 3);
    }

    public function test_user_changes_single_choice_ballot_without_duplicate(): void
    {
        $organizer = User::factory()->create();
        $voter = User::factory()->create();
        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'allows_vote_changes' => '1',
        ])->assertRedirect();
        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $poll = $session->polls()->firstOrFail();
        $options = $poll->options()->pluck('id');

        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[0]]])
            ->assertSessionHas('status');
        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[1]]])
            ->assertSessionHas('status');

        $this->assertDatabaseCount('coordination_ballots', 1);
        $this->assertDatabaseCount('coordination_ballot_selections', 1);
        $this->assertDatabaseHas('coordination_ballot_selections', ['option_id' => $options[1]]);
        $this->assertDatabaseMissing('coordination_ballot_selections', ['option_id' => $options[0]]);
    }

    public function test_open_poll_shows_voters_and_anonymous_poll_hides_them(): void
    {
        $organizer = User::factory()->create(['username' => 'poll_owner']);
        $voter = User::factory()->create(['username' => 'visible_voter']);

        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'results_visibility' => 'always',
            'is_anonymous' => '0',
        ])->assertRedirect();

        $openSession = CoordinationSession::query()->latest('id')->firstOrFail();
        $openOption = $openSession->polls()->firstOrFail()->options()->firstOrFail();
        $this->actingAs($voter)
            ->post(route('coordination.vote', $openSession), ['option_ids' => [$openOption->id]])
            ->assertSessionHas('status');
        $this->actingAs($organizer)
            ->get(route('coordination.show', $openSession))
            ->assertOk()
            ->assertSee('visible_voter');

        $this->actingAs($organizer)->post(route('coordination.store'), [
            ...$this->payload(),
            'results_visibility' => 'always',
            'is_anonymous' => '1',
        ])->assertRedirect();

        $anonymousSession = CoordinationSession::query()->latest('id')->firstOrFail();
        $anonymousOption = $anonymousSession->polls()->firstOrFail()->options()->firstOrFail();
        $this->actingAs($voter)
            ->post(route('coordination.vote', $anonymousSession), ['option_ids' => [$anonymousOption->id]])
            ->assertSessionHas('status');
        $this->actingAs($organizer)
            ->get(route('coordination.show', $anonymousSession))
            ->assertOk()
            ->assertDontSee('visible_voter');
    }

    public function test_user_cannot_change_ballot_when_poll_forbids_vote_changes(): void
    {
        $organizer = User::factory()->create();
        $voter = User::factory()->create();

        $this->actingAs($organizer)
            ->post(route('coordination.store'), [
                ...$this->payload(),
                'allows_vote_changes' => '0',
            ])
            ->assertRedirect();

        $session = CoordinationSession::query()->latest('id')->firstOrFail();
        $poll = $session->polls()->firstOrFail();
        $options = $poll->options()->pluck('id');

        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[0]]])
            ->assertSessionHas('status');
        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$options[1]]])
            ->assertSessionHas('error', 'В этом опросе нельзя изменить голос.');

        $this->assertFalse($poll->fresh()->allows_vote_changes);
        $this->assertDatabaseCount('coordination_ballots', 1);
        $this->assertDatabaseCount('coordination_ballot_selections', 1);
        $this->assertDatabaseHas('coordination_ballot_selections', ['option_id' => $options[0]]);
        $this->assertDatabaseMissing('coordination_ballot_selections', ['option_id' => $options[1]]);

        $this->actingAs($voter)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Ваш голос принят и не может быть изменён.')
            ->assertDontSee('Изменить голос');
    }

    public function test_expired_poll_rejects_vote_and_scheduler_closes_it(): void
    {
        $organizer = User::factory()->create();
        $voter = User::factory()->create();
        $session = $this->createSession($organizer);
        $poll = $session->polls()->firstOrFail();
        $poll->forceFill(['closes_at' => now()->subMinute()])->save();

        $this->actingAs($voter)
            ->post(route('coordination.vote', $session), ['option_ids' => [$poll->options()->firstOrFail()->id]])
            ->assertSessionHas('error', 'Голосование уже закрыто.');

        $this->artisan('coordination:close-expired')->assertSuccessful();

        $this->assertSame(PollStatusEnum::CLOSED, $poll->fresh()->status);
        $this->assertSame(CoordinationSessionStatusEnum::DECISION_PENDING, $session->fresh()->status);
        $this->assertDatabaseCount('coordination_ballots', 0);

        $this->app['auth']->logout();
        $this->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Закрыт')
            ->assertSee(
                '<span class="page-breadcrumbs__current" aria-current="page">'.$session->title.'</span>',
                false,
            )
            ->assertDontSee('до '.$poll->closes_at->format('d.m.Y H:i'))
            ->assertSee('Режим голосования: Выбор одного варианта')
            ->assertDontSee('Войдите, чтобы проголосовать.');
    }

    public function test_only_creator_closes_and_accepts_explicit_result(): void
    {
        $organizer = User::factory()->create();
        $stranger = User::factory()->create();
        $session = $this->createSession($organizer);
        $poll = $session->polls()->firstOrFail();
        $optionIds = $poll->options()->pluck('id');

        $this->actingAs($stranger)
            ->post(route('coordination.close', $session))
            ->assertSessionHas('error', 'Закрыть опрос может только его создатель.');
        $this->assertSame(PollStatusEnum::OPEN, $poll->fresh()->status);

        $this->actingAs($organizer)
            ->post(route('coordination.close', $session))
            ->assertSessionHas('status');
        $this->assertSame(CoordinationSessionStatusEnum::DECISION_PENDING, $session->fresh()->status);
        $this->assertDatabaseCount('coordination_decisions', 0);

        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), ['option_id' => $optionIds[1]])
            ->assertSessionHas('status');
        $this->assertSame(CoordinationSessionStatusEnum::COMPLETED, $session->fresh()->status);
        $this->assertDatabaseHas('coordination_decisions', [
            'session_id' => $session->id,
            'option_id' => $optionIds[1],
        ]);

        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), ['option_id' => $optionIds[2]])
            ->assertSessionHas('status');
        $this->assertDatabaseCount('coordination_decisions', 1);
        $this->assertDatabaseMissing('coordination_decisions', [
            'session_id' => $session->id,
            'option_id' => $optionIds[2],
        ]);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_one_poll_can_have_publications_in_several_chats(): void
    {
        $session = $this->createSession(User::factory()->create());
        $poll = $session->polls()->firstOrFail();
        $firstChat = TelegramChat::query()->create(['telegram_chat_id' => -1001, 'title' => 'Основной']);
        $secondChat = TelegramChat::query()->create(['telegram_chat_id' => -1002, 'title' => 'Север']);

        TelegramCoordinationPublication::query()->create(['poll_id' => $poll->id, 'chat_id' => $firstChat->id]);
        TelegramCoordinationPublication::query()->create(['poll_id' => $poll->id, 'chat_id' => $secondChat->id]);

        $this->assertDatabaseCount('telegram_coordination_publications', 2);
        $this->assertCount(1, $firstChat->coordinationPublications);
        $this->assertCount(1, $secondChat->coordinationPublications);
    }

    public function test_creator_explicitly_creates_event_from_accepted_decision_idempotently(): void
    {
        $organizer = User::factory()->create();
        $session = $this->createSession($organizer);
        $poll = $session->polls()->firstOrFail();
        $option = $poll->options()->where('label', '20:00')->firstOrFail();
        [$venue, $startsAt] = $this->availableVenue();

        $this->actingAs($organizer)
            ->post(route('coordination.close', $session))
            ->assertSessionHas('status');
        $this->actingAs($organizer)
            ->post(route('coordination.decision', $session), ['option_id' => $option->id])
            ->assertSessionHas('status');
        $this->actingAs($organizer)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('Создать мероприятие')
            ->assertSee('Согласованный вариант: 20:00')
            ->assertSee('name="venue_id"', false);

        $payload = $this->eventPayload($venue, $startsAt);
        $firstResponse = $this->actingAs($organizer)
            ->post(route('coordination.event.store', $session), $payload);
        $event = $session->fresh()->eventTransition()->firstOrFail()->event()->firstOrFail();

        $firstResponse->assertRedirect(route('events.show', $event->routeIdentifier()));
        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);
        $this->assertDatabaseHas('coordination_event_transitions', [
            'session_id' => $session->id,
            'decision_id' => $session->decision()->firstOrFail()->id,
            'event_id' => $event->id,
        ]);

        $this->actingAs($organizer)
            ->post(route('coordination.event.store', $session), $payload)
            ->assertRedirect(route('events.show', $event->routeIdentifier()));

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('venue_bookings', 1);
        $this->assertDatabaseCount('coordination_event_transitions', 1);
        $this->actingAs($organizer)
            ->get(route('coordination.show', $session))
            ->assertOk()
            ->assertSee('По этому решению уже создано мероприятие.')
            ->assertDontSee('Перед созданием система повторно проверит');
    }

    public function test_event_transition_requires_accepted_decision_and_creator(): void
    {
        $organizer = User::factory()->create();
        $stranger = User::factory()->create();
        $session = $this->createSession($organizer);
        [$venue, $startsAt] = $this->availableVenue();
        $payload = $this->eventPayload($venue, $startsAt);

        $this->actingAs($organizer)
            ->post(route('coordination.event.store', $session), $payload)
            ->assertSessionHas('error', 'Сначала закройте голосование и примите итоговый вариант.');

        $this->actingAs($organizer)->post(route('coordination.close', $session));
        $this->actingAs($organizer)->post(route('coordination.decision', $session), [
            'option_id' => $session->polls()->firstOrFail()->options()->firstOrFail()->id,
        ]);

        $this->actingAs($stranger)
            ->post(route('coordination.event.store', $session), $payload)
            ->assertSessionHas('error', 'Создать мероприятие может только создатель опроса.');

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('coordination_event_transitions', 0);
    }

    public function test_failed_event_invariants_do_not_create_transition(): void
    {
        $organizer = User::factory()->create();
        $session = $this->createSession($organizer);
        $poll = $session->polls()->firstOrFail();
        $this->actingAs($organizer)->post(route('coordination.close', $session));
        $this->actingAs($organizer)->post(route('coordination.decision', $session), [
            'option_id' => $poll->options()->firstOrFail()->id,
        ]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(12, 0);

        $this->actingAs($organizer)
            ->post(route('coordination.event.store', $session), $this->eventPayload($venue, $startsAt))
            ->assertSessionHas('error', 'Создать мероприятие можно только на подтверждённой площадке.');

        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('coordination_event_transitions', 0);
    }

    private function createSession(User $organizer): CoordinationSession
    {
        $this->actingAs($organizer)->post(route('coordination.store'), $this->payload())->assertRedirect();

        return CoordinationSession::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Игра вечером',
            'question' => 'Во сколько играем?',
            'description' => 'Сначала выберем удобное время.',
            'subject_type' => 'text',
            'selection_mode' => 'single',
            'results_visibility' => 'after_vote',
            'allows_vote_changes' => '0',
            'is_anonymous' => '0',
            'closes_at' => CarbonImmutable::now()->addDay()->format('Y-m-d H:i:s'),
            'options' => ['19:00', '20:00', '21:00'],
        ];
    }

    /**
     * @param  array<int, Venue>  $venues
     * @return array<string, mixed>
     */
    private function chainPayload(array $venues, CarbonImmutable $startsAt): array
    {
        return [
            'flow_type' => CoordinationFlowTypeEnum::EVENT_SCHEDULING->value,
            'title' => 'Согласование мероприятия',
            'description' => 'Выбираем дату, время и площадку.',
            'results_visibility' => 'after_vote',
            'allows_vote_changes' => '0',
            'is_anonymous' => '0',
            'publish_to_telegram' => '0',
            'closes_at' => CarbonImmutable::now()->addHour()->format('Y-m-d H:i:s'),
            'step_duration_minutes' => 60,
            'date_options' => [
                $startsAt->format('Y-m-d'),
                $startsAt->addDay()->format('Y-m-d'),
            ],
            'time_options' => [
                [
                    'starts_at' => $startsAt->format('H:i'),
                    'ends_at' => $startsAt->addHour()->format('H:i'),
                ],
                [
                    'starts_at' => $startsAt->addHours(2)->format('H:i'),
                    'ends_at' => $startsAt->addHours(3)->format('H:i'),
                ],
            ],
            'venue_options' => array_map(
                static fn (Venue $venue): int => $venue->id,
                $venues,
            ),
        ];
    }

    /** @return array{Venue, CarbonImmutable} */
    private function availableVenue(): array
    {
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(12, 0);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => $startsAt->isoWeekday(),
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'sort_order' => 0,
        ]);

        return [$venue, $startsAt];
    }

    /** @return array<string, mixed> */
    private function eventPayload(Venue $venue, CarbonImmutable $startsAt): array
    {
        return [
            'venue_id' => $venue->id,
            'title' => 'Игра после опроса',
            'type' => 'game_training',
            'visibility' => 'public',
            'description' => 'Согласованный вариант: 20:00',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'duration_minutes' => 60,
            'max_participants' => 10,
        ];
    }
}
