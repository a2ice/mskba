<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Events\EventBackofficeEditor;
use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Filament\Backoffice\Resources\Events\RelationManagers\ParticipantsRelationManager;
use App\Modules\Event\Application\UseCases\UpdateManagedEventParticipantStatusHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Application\Services\EventIdentityParticipationService;
use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\UseCases\AddEventParticipantHandler;
use App\Modules\Event\Application\UseCases\JoinEventHandler;
use App\Modules\Event\Application\UseCases\SetEventParticipationHandler;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Livewire\Livewire;
use Illuminate\Validation\ValidationException;
use App\Filament\Backoffice\Resources\Events\Pages\CreateEvent;
use App\Filament\Backoffice\Resources\Events\Pages\EditEvent;
use Tests\TestCase;

final class BackofficeEventManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        $user = User::factory()->create([
            'system_role' => $role, 'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_superadmin_can_create_and_edit_event_with_booking_and_organizer(): void
    {
        Queue::fake();
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get(EventResource::getUrl('index'))->assertOk();
        $this->get(EventResource::getUrl('create'))->assertOk();

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $startsAt = now()->addDays(2)->setTime(18, 0)->format('Y-m-d H:i');

        $editor = app(EventBackofficeEditor::class);
        $event = $editor->createEvent([
            'title' => 'Тренировка из Filament',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => $startsAt,
            'duration_minutes' => 90,
            'max_participants' => 10,
        ]);

        $this->assertSame(EventStatusEnum::PUBLISHED, $event->status);
        $this->assertSame($venue->primaryCourt()->first()->id, $event->venue_court_id);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);
        $this->assertSame(1, $event->participants()->count());
        $this->assertSame(1, $event->booking()->count());
        $this->get(EventResource::getUrl('edit', ['record' => $event]))->assertOk();

        $data = [
            'title' => 'Обновлённая тренировка',
            'venue_id' => $venue->id,
            'venue_court_id' => $event->venue_court_id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'description' => 'Обновлено в админке',
            'starts_at' => $startsAt,
            'duration_minutes' => 90,
            'max_participants' => 10,
            'booking_scope' => 'whole',
        ];
        $updated = $editor->updateEvent($event, $data);
        $this->assertSame('Обновлённая тренировка', $updated->title);
        $this->assertSame('Обновлено в админке', $updated->description);
        $this->assertSame(1, $updated->booking()->count());
    }

    public function test_editing_event_start_time_persists_new_moscow_clock_and_moves_linked_booking(): void
    {
        // Reproduce a Moscow venue on a UTC app / DB connection.
        // Eloquent date bindings omit offsets, so this catches a +3h drift.
        config()->set('app.timezone', 'UTC');
        Queue::fake();
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        \App\Modules\Venue\Domain\Models\VenueSchedule::factory()
            ->for($venue)->create(['timezone' => 'Europe/Moscow']);
        $start = now('Europe/Moscow')->addDays(3)->setTime(22, 0)->format('Y-m-d H:i');
        $editor = app(EventBackofficeEditor::class);
        $event = $editor->createEvent([
            'title' => 'Проверка обновления времени',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => $start,
            'duration_minutes' => 90,
            'max_participants' => 10,
        ]);

        $event->refresh();
        $this->assertSame('22:00', $event->starts_at->setTimezone('Europe/Moscow')->format('H:i'));
        $this->assertSame('22:00', $event->booking->fresh()->starts_at->setTimezone('Europe/Moscow')->format('H:i'));

        Livewire::test(EditEvent::class, ['record' => $event->id])
            ->assertSet('data.event_time', '22:00')
            ->set('data.event_time', '19:00')
            ->call('save')
            ->assertHasNoErrors();

        $event->refresh();
        $this->assertSame('19:00', $event->starts_at->setTimezone('Europe/Moscow')->format('H:i'));
        $this->assertSame('19:00', $event->booking->fresh()->starts_at->setTimezone('Europe/Moscow')->format('H:i'));
        $this->assertSame(
            $event->starts_at->timestamp,
            $event->booking->fresh()->starts_at->timestamp,
            'Linked booking must move with the event.',
        );

        Livewire::test(EditEvent::class, ['record' => $event->id])
            ->assertSet('data.event_time', '19:00');
    }

    /**
     * Mirrors scripts/staging/import-public-venues-fixture-20261019.sh:
     * direct INSERT into venues bypasses the Eloquent created hook that
     * normally inserts the primary venue_courts row.
     */
    public function test_livewire_creates_event_for_imported_school_1794_without_courts(): void
    {
        Queue::fake();
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venueId = DB::table('venues')->insertGetId([
            'name' => 'Школа №1794',
            'alias' => 'shkola-1794',
            'type' => 'street_court',
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => 'active',
            'requires_payment' => false,
            'requires_booking_approval' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $venue = Venue::query()->findOrFail($venueId);
        $this->assertSame(0, VenueCourt::query()->where('venue_id', $venue->id)->count());

        $start = now('Europe/Moscow')->addDay()->setTime(19, 0)->format('Y-m-d H:i');

        Livewire::test(CreateEvent::class)
            ->set('data.title', 'Игра на Школе 1794')
            ->set('data.type', EventTypeEnum::TRAINING->value)
            ->set('data.visibility', EventVisibilityEnum::PUBLIC->value)
            ->set('data.venue_id', $venue->id)
            ->assertSee('На площадке нет отдельных игровых зон')
            ->set('data.booking_scope', 'whole')
            ->set('data.event_date', substr($start, 0, 10))
            ->set('data.event_time', substr($start, 11, 5))
            ->set('data.duration_minutes', 90)
            ->call('create')
            ->assertHasNoErrors();

        $event = \App\Modules\Event\Domain\Models\Event::query()
            ->where('title', 'Игра на Школе 1794')->firstOrFail();
        $this->assertSame($venue->id, $event->venue_id);
        $this->assertNull($event->venue_court_id);
        $this->assertNull($event->booking->venue_court_id);
        $this->assertSame(VenueBookingStatusEnum::CONFIRMED, $event->booking->status);

        $edited = app(EventBackofficeEditor::class)->updateEvent($event, [
            'title' => 'Игра на Школе 1794 — обновлено',
            'venue_id' => $venue->id,
            'venue_court_id' => null,
            'booking_scope' => 'whole',
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => $start,
            'duration_minutes' => 90,
        ]);
        $this->assertNull($edited->venue_court_id);
        $this->assertSame('Игра на Школе 1794 — обновлено', $edited->title);

        try {
            app(EventBackofficeEditor::class)->createEvent([
                'title' => 'Дубль',
                'venue_id' => $venue->id,
                'type' => EventTypeEnum::TRAINING->value,
                'visibility' => EventVisibilityEnum::PUBLIC->value,
                'starts_at' => $start,
                'duration_minutes' => 90,
            ]);
            $this->fail('A legacy venue-wide booking must still prevent double booking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.event_time', $exception->errors());
        }
    }

    public function test_livewire_create_form_creates_event_and_its_booking(): void
    {
        Queue::fake();
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $starts = now('Europe/Moscow')->addDays(2)->setTime(19, 0)->format('Y-m-d H:i');

        Livewire::test(CreateEvent::class)
            ->set('data.title', 'Пробная игра с экрана Filament')
            ->set('data.type', EventTypeEnum::TRAINING->value)
            ->set('data.visibility', EventVisibilityEnum::PUBLIC->value)
            ->set('data.venue_id', $venue->id)
            ->set('data.booking_scope', 'whole')
            ->set('data.event_date', substr($starts, 0, 10))
            ->set('data.event_time', substr($starts, 11, 5))
            ->set('data.duration_minutes', 180)
            ->call('create')
            ->assertHasNoErrors();

        $event = \App\Modules\Event\Domain\Models\Event::query()
            ->where('title', 'Пробная игра с экрана Filament')->firstOrFail();
        $this->assertSame($venue->id, $event->venue_id);
        $this->assertSame(1, $event->booking()->count());
    }

    public function test_event_conflict_message_is_attached_to_time_field_not_hidden_title_field(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN));
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $data = [
            'title' => 'Первая игра',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(2)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 120,
        ];
        app(EventBackofficeEditor::class)->createEvent($data);

        try {
            app(EventBackofficeEditor::class)->createEvent(array_replace($data, ['title' => 'Пересекающаяся игра']));
            $this->fail('Expected booking conflict');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.event_time', $exception->errors());
            $this->assertArrayNotHasKey('data.title', $exception->errors());
        }
    }

    public function test_selecting_organizer_without_username_renders_form(): void
    {
        $admin = $this->user(Role::SUPERADMIN);
        $user = $this->user(Role::USER);
        $user->forceFill(['username' => null])->save();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateEvent::class)
            ->set('data.organizer_user_id', $user->id)
            ->assertSee('Пользователь #'.$user->id)
            ->assertHasNoErrors();
    }

    public function test_superadmin_can_choose_another_event_organizer_in_livewire_form(): void
    {
        Queue::fake();
        $super = $this->user(Role::SUPERADMIN);
        $organizer = $this->user(Role::USER);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);

        Livewire::test(CreateEvent::class)
            ->set('data.title', 'Тренировка другого организатора')
            ->set('data.type', EventTypeEnum::TRAINING->value)
            ->set('data.visibility', EventVisibilityEnum::PUBLIC->value)
            ->set('data.organizer_user_id', $organizer->id)
            ->set('data.venue_id', $venue->id)
            ->set('data.event_date', now('Europe/Moscow')->addDays(2)->format('Y-m-d'))
            ->set('data.event_time', '18:00')
            ->set('data.duration_minutes', 90)
            ->call('create')
            ->assertHasNoErrors();

        $event = \App\Modules\Event\Domain\Models\Event::query()
            ->where('title', 'Тренировка другого организатора')->firstOrFail();
        $this->assertSame($organizer->id, $event->organizerActor->user_id);
    }

    public function test_livewire_game_creation_sets_up_game_and_booking(): void
    {
        Queue::fake();
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);

        Livewire::test(CreateEvent::class)
            ->set('data.title', 'Игра 3×3 из админки')
            ->set('data.type', EventTypeEnum::GAME->value)
            ->set('data.visibility', EventVisibilityEnum::PUBLIC->value)
            ->set('data.venue_id', $venue->id)
            ->set('data.booking_scope', 'whole')
            ->set('data.event_date', now('Europe/Moscow')->addDays(3)->format('Y-m-d'))
            ->set('data.event_time', '19:00')
            ->set('data.duration_minutes', 120)
            ->set('data.game_format', 'streetball_3x3')
            ->set('data.game_recruitment_mode', 'preformed_teams')
            ->set('data.side_a_size', 3)
            ->set('data.side_b_size', 3)
            ->set('data.scoring_type', 'streetball')
            ->set('data.timing_mode', 'whole_game')
            ->set('data.game_accepts_applications', true)
            ->call('create')
            ->assertHasNoErrors();

        $event = \App\Modules\Event\Domain\Models\Event::query()
            ->where('title', 'Игра 3×3 из админки')->firstOrFail();
        $this->assertSame(1, $event->booking()->count());
        $this->assertSame(1, $event->games()->count());
    }

    public function test_default_event_date_is_today_in_moscow_before_evening(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-24 10:53:00', 'Europe/Moscow'));
        try {
            $this->assertSame('2026-10-24', EventResource::defaultEventDate());
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function test_create_form_requires_explicit_time_but_prefills_todays_date(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateEvent::class)
            ->assertSet('data.event_date', fn ($value): bool => str_starts_with((string) $value, EventResource::defaultEventDate()))
            ->assertSet('data.event_time', null)
            ->set('data.title', 'Без времени')
            ->call('create')
            ->assertHasErrors(['data.event_time' => 'required']);
    }

    public function test_duration_select_has_half_hour_steps_up_to_eight_hours(): void
    {
        $options = EventResource::durationOptions();
        $this->assertCount(16, $options);
        $this->assertSame('30 минут', $options[30]);
        $this->assertSame('1 ч', $options[60]);
        $this->assertSame('1 ч 30 мин', $options[90]);
        $this->assertSame('8 ч', $options[480]);
    }

    public function test_livewire_attendance_checkbox_reveals_default_player_role(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN));
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateEvent::class)
            ->assertSet('data.organizer_participates', false)
            ->set('data.organizer_participates', true)
            ->assertSet('data.participation_roles', ['player'])
            ->assertSee('В качестве кого');
    }

    public function test_default_attendance_checkboxes_use_all_active_profile_roles_and_fallback_player(): void
    {
        $organizer = $this->user(Role::USER);
        $this->assertSame(['player'], EventResource::defaultParticipationRoles($organizer->id));

        foreach (['coach', 'statistician'] as $role) {
            UserParticipationRole::query()->create([
                'user_id' => $organizer->id,
                'role' => $role,
                'assigner' => UserParticipationRoleAssignerEnum::USER,
            ]);
        }

        $this->assertEqualsCanonicalizing(
            ['coach', 'statistician'],
            EventResource::defaultParticipationRoles($organizer->id),
        );
        $this->assertArrayNotHasKey('organizer', EventResource::participationRoleOptions());
    }

    public function test_organizer_can_manage_event_without_attending_or_occupying_player_seat(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);

        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Организую без участия',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(3)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'organizer_participates' => false,
            'participation_roles' => ['player'],
        ]);

        $participant = $event->participants()->firstOrFail();
        $this->assertSame($organizer->id, $event->organizerActor->user_id);
        $this->assertSame(EventParticipantRoleEnum::ORGANIZER, $participant->role);
        $this->assertSame(EventParticipantStatusEnum::LEFT, $participant->status);
        $this->assertSame([], $participant->participation_roles);
        $this->assertSame(0, $event->participants()->occupiesPlayerSpot()->count());
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
        $this->assertTrue(app(EventManagementAccess::class)->allows(
            $event, $event->organizerActor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS,
        ));
    }

    public function test_multiple_organizer_roles_count_one_player_only_when_player_is_selected(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $data = [
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(3)->setTime(17, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'organizer_participates' => true,
        ];

        $staffEvent = app(EventBackofficeEditor::class)->createEvent([
            ...$data,
            'title' => 'Тренер и статист',
            'participation_roles' => ['coach', 'statistician'],
        ]);
        $this->assertSame(['coach', 'statistician'], $staffEvent->participants()->firstOrFail()->participation_roles);
        $this->assertSame(0, $staffEvent->participants()->occupiesPlayerSpot()->count());

        $playerEvent = app(EventBackofficeEditor::class)->createEvent([
            ...$data,
            'title' => 'Игрок и тренер',
            'starts_at' => now('Europe/Moscow')->addDays(4)->setTime(17, 0)->format('Y-m-d H:i'),
            'participation_roles' => ['player', 'coach'],
        ]);
        $this->assertSame(1, $playerEvent->participants()->occupiesPlayerSpot()->count());
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($playerEvent));

        try {
            app(EventBackofficeEditor::class)->createEvent([
                ...$data,
                'title' => 'Не указаны роли',
                'starts_at' => now('Europe/Moscow')->addDays(5)->setTime(17, 0)->format('Y-m-d H:i'),
                'participation_roles' => [],
            ]);
            $this->fail('Enabled attendance requires at least one role.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.participation_roles', $exception->errors());
        }
    }

    public function test_event_invitation_preserves_multiple_staff_roles_and_does_not_fill_player_spot(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $coach = $this->user(Role::USER);
        $statistician = $this->user(Role::USER);
        $player = $this->user(Role::USER);
        $this->actingAs($organizer);

        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Приглашения разных ролей',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(7)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'max_participants' => 1,
            'organizer_participates' => false,
        ]);

        app(AddEventParticipantHandler::class)->handle(
            $event->routeIdentifier(), $event->organizerActor, $coach->id,
            asBackoffice: true, participationRoles: ['coach', 'media'],
        );
        app(AddEventParticipantHandler::class)->handle(
            $event->routeIdentifier(), $event->organizerActor, $statistician->id,
            asBackoffice: true, participationRoles: ['statistician'],
        );
        $this->assertSame(
            EventParticipantStatusEnum::TENTATIVE,
            $event->participants()->where('user_id', $coach->id)->firstOrFail()->status,
        );
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        // Both existing invitation acceptance workflows must preserve the
        // selected staff roles and should never allocate a player slot.
        app(JoinEventHandler::class)->handle($event->routeIdentifier(), $coach);
        app(SetEventParticipationHandler::class)->handle(
            $event->routeIdentifier(), $statistician, EventParticipantStatusEnum::CONFIRMED,
        );
        $this->assertSame(
            ['coach', 'media'],
            $event->participants()->where('user_id', $coach->id)->firstOrFail()->participation_roles,
        );
        $this->assertSame(
            ['statistician'],
            $event->participants()->where('user_id', $statistician->id)->firstOrFail()->participation_roles,
        );
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        app(AddEventParticipantHandler::class)->handle(
            $event->routeIdentifier(), $event->organizerActor, $player->id,
            asBackoffice: true, participationRoles: ['player', 'coach'],
        );
        app(JoinEventHandler::class)->handle($event->routeIdentifier(), $player);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
    }

    public function test_backoffice_can_edit_organizer_actual_attendance_without_changing_organizer_privileges(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $invitee = $this->user(Role::USER);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Организатор меняет своё участие',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(12)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'max_participants' => 1,
            'organizer_participates' => true,
            'participation_roles' => ['player'],
        ]);
        $actor = $event->organizerActor;
        $record = $event->participants()->where('user_id', $organizer->id)->firstOrFail();
        $editor = app(UpdateManagedEventParticipantStatusHandler::class);
        $this->assertSame(['player'], ParticipantsRelationManager::participationRolesForEdit($record));
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
        $originalJoinedAt = $record->joined_at;

        // Editing only the organizer's functions does not create another seat
        // or change their event ownership or management permission.
        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['coach', 'statistician']);
        $this->assertSame(EventParticipantRoleEnum::ORGANIZER, $record->refresh()->role);
        $this->assertSame(EventParticipantStatusEnum::CONFIRMED, $record->status);
        $this->assertSame(['coach', 'statistician'], $record->participation_roles);
        $this->assertEquals($originalJoinedAt, $record->joined_at);
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        app(AddEventParticipantHandler::class)->handle(
            $event->routeIdentifier(), $actor, $invitee->id,
            asBackoffice: true, participationRoles: ['player']);
        $inviteeRecord = $event->participants()->where('user_id', $invitee->id)->firstOrFail();
        $editor->handle($event->routeIdentifier(), $inviteeRecord->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['player']);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        // The confirmed organizer may still be staff, but cannot join as an
        // additional player once the last seat belongs to someone else.
        try {
            $editor->handle($event->routeIdentifier(), $record->id, $actor,
                EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
                participationRoles: ['player', 'coach']);
            $this->fail('Organizer player role cannot exceed the event limit.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Все места', $exception->getMessage());
        }
        $this->assertSame(['coach', 'statistician'], $record->refresh()->participation_roles);

        // Organizer stops attending; their ACL and ownership remain.
        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::LEFT, asBackoffice: true,
            participationRoles: []);
        $this->assertSame(EventParticipantRoleEnum::ORGANIZER, $record->refresh()->role);
        $this->assertSame(EventParticipantStatusEnum::LEFT, $record->status);
        $this->assertSame([], $record->participation_roles);
        $this->assertSame($actor->id, $event->fresh()->organizer_actor_id);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
        $this->assertTrue(app(EventManagementAccess::class)->allows(
            $event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS,
        ));

        // Backoffice can restore non-player attendance even with a full roster.
        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['coach']);
        $this->assertSame(['coach'], $record->refresh()->participation_roles);
        $this->assertSame(EventParticipantRoleEnum::ORGANIZER, $record->role);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
    }

    public function test_organizer_roles_validation_and_legacy_default_are_safe(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Организатор с исторической записью',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(13)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'organizer_participates' => true,
            'participation_roles' => ['player'],
        ]);
        $record = $event->participants()->firstOrFail();
        $record->forceFill(['participation_roles' => null])->save();
        $this->assertSame(['player'], ParticipantsRelationManager::participationRolesForEdit($record->refresh()));
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
        $editor = app(UpdateManagedEventParticipantStatusHandler::class);

        foreach ([
            [EventParticipantStatusEnum::CONFIRMED, [], true],
            [EventParticipantStatusEnum::TENTATIVE, ['coach'], true],
            [EventParticipantStatusEnum::LEFT, ['coach'], true],
            [EventParticipantStatusEnum::CONFIRMED, ['coach'], false],
        ] as [$status, $roles, $backoffice]) {
            try {
                $editor->handle($event->routeIdentifier(), $record->id, $event->organizerActor,
                    $status, asBackoffice: $backoffice, participationRoles: $roles);
                $this->fail('Invalid organizer participation edit must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Для организатора', $exception->getMessage());
            }
        }
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
        $editor->handle($event->routeIdentifier(), $record->id, $event->organizerActor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['referee']);
        $this->assertSame(['referee'], $record->refresh()->participation_roles);
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
    }

    public function test_editing_participant_roles_preserves_status_and_only_player_role_occupies_one_spot(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $coach = $this->user(Role::USER);
        $another = $this->user(Role::USER);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Редактирование ролей без двойного счёта',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(9)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'max_participants' => 1,
            'organizer_participates' => false,
        ]);
        $actor = $event->organizerActor;
        $invite = app(AddEventParticipantHandler::class);
        $editor = app(UpdateManagedEventParticipantStatusHandler::class);
        $invite->handle($event->routeIdentifier(), $actor, $coach->id,
            asBackoffice: true, participationRoles: ['coach']);
        $record = $event->participants()->where('user_id', $coach->id)->firstOrFail();
        $this->assertSame(['coach'], ParticipantsRelationManager::participationRolesForEdit($record));

        // Edit only functions, without changing tentative status.
        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::TENTATIVE, asBackoffice: true,
            participationRoles: ['coach', 'statistician']);
        $this->assertSame(EventParticipantStatusEnum::TENTATIVE, $record->refresh()->status);
        $this->assertSame(['coach', 'statistician'], $record->participation_roles);
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['coach', 'statistician']);
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        // Adding 'player' to already-confirmed staff consumes exactly one place.
        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['coach', 'statistician', 'player']);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        $invite->handle($event->routeIdentifier(), $actor, $another->id,
            asBackoffice: true, participationRoles: ['media']);
        $other = $event->participants()->where('user_id', $another->id)->firstOrFail();
        $editor->handle($event->routeIdentifier(), $other->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['media']);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        // A full player roster prevents a staff member changing into a player.
        try {
            $editor->handle($event->routeIdentifier(), $other->id, $actor,
                EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
                participationRoles: ['media', 'player']);
            $this->fail('Confirmed staff must not exceed player capacity.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Все места', $exception->getMessage());
        }
        $this->assertSame(['media'], $other->refresh()->participation_roles);

        // Releasing player status from the first participant opens the seat.
        $editor->handle($event->routeIdentifier(), $record->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['coach', 'statistician']);
        $editor->handle($event->routeIdentifier(), $other->id, $actor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['media', 'player']);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));
        $this->assertEqualsCanonicalizing(['media', 'player'],
            ParticipantsRelationManager::participationRolesForEdit($other->refresh()));
    }

    public function test_editing_legacy_participant_prefills_player_but_role_free_status_change_preserves_null(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $invitee = $this->user(Role::USER);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Редактирование старого участия',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(10)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'organizer_participates' => false,
        ]);
        app(AddEventParticipantHandler::class)->handle($event->routeIdentifier(),
            $event->organizerActor, $invitee->id, asBackoffice: true);
        $participant = $event->participants()->where('user_id', $invitee->id)->firstOrFail();
        $participant->forceFill(['participation_roles' => null])->save();
        $this->assertSame(['player'], ParticipantsRelationManager::participationRolesForEdit($participant->refresh()));

        // Existing status-only callers must keep legacy NULL roles untouched.
        app(UpdateManagedEventParticipantStatusHandler::class)->handle(
            $event->routeIdentifier(), $participant->id, $event->organizerActor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true);
        $this->assertNull($participant->refresh()->participation_roles);
        $this->assertSame(1, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        // Backoffice may explicitly classify that old record as staff instead.
        app(UpdateManagedEventParticipantStatusHandler::class)->handle(
            $event->routeIdentifier(), $participant->id, $event->organizerActor,
            EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
            participationRoles: ['referee']);
        $this->assertSame(['referee'], $participant->refresh()->participation_roles);
        $this->assertSame(0, app(EventIdentityParticipationService::class)->confirmedIdentityCount($event));

        try {
            app(UpdateManagedEventParticipantStatusHandler::class)->handle(
                $event->routeIdentifier(), $participant->id, $event->organizerActor,
                EventParticipantStatusEnum::CONFIRMED, asBackoffice: true,
                participationRoles: []);
            $this->fail('Empty role selection must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Выберите хотя бы одну', $exception->getMessage());
        }
        $this->assertSame(['referee'], $participant->refresh()->participation_roles);
    }

    public function test_inviting_participant_without_selected_role_fails_on_server(): void
    {
        Queue::fake();
        $organizer = $this->user(Role::SUPERADMIN);
        $attendee = $this->user(Role::USER);
        $this->actingAs($organizer);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $event = app(EventBackofficeEditor::class)->createEvent([
            'title' => 'Проверка ролей приглашения',
            'venue_id' => $venue->id,
            'type' => EventTypeEnum::TRAINING->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => now('Europe/Moscow')->addDays(8)->setTime(18, 0)->format('Y-m-d H:i'),
            'duration_minutes' => 90,
            'organizer_participates' => false,
        ]);

        try {
            app(AddEventParticipantHandler::class)->handle(
                $event->routeIdentifier(), $event->organizerActor, $attendee->id,
                asBackoffice: true, participationRoles: [],
            );
            $this->fail('A blank invitation role set must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Выберите хотя бы одну', $e->getMessage());
        }
        $this->assertFalse($event->participants()->where('user_id', $attendee->id)->exists());
    }

    public function test_event_management_is_delegated_separately_from_venue_management(): void
    {
        $super = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        $this->actingAs($manager);
        $this->assertFalse(EventResource::canViewAny());

        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_EVENT_MANAGE->value,
        ]);
        $this->flushSession();
        $this->actingAs($manager);
        $this->assertTrue(EventResource::canViewAny());
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get(EventResource::getUrl('index'))->assertOk();
    }
}
