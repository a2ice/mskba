<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Events\EventBackofficeEditor;
use App\Filament\Backoffice\Resources\Events\EventResource;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
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
