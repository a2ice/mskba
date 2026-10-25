<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\VenueBooking;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class VenueAppScheduleActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-08 12:00:00');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_schedule_contains_working_hours_and_exceptions_but_no_booking_slots(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $schedule = $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $schedule->intervals()->create([
            'day_of_week' => 2,
            'starts_at' => '09:00',
            'ends_at' => '21:00',
            'sort_order' => 1,
        ]);
        $schedule->exceptions()->create([
            'date' => '2026-09-10',
            'is_closed' => true,
        ]);
        $shift = $schedule->exceptions()->create([
            'date' => '2026-09-11',
            'is_closed' => false,
        ]);
        $shift->intervals()->create([
            'starts_at' => '12:00',
            'ends_at' => '14:00',
            'sort_order' => 1,
        ]);

        Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Открытая тренировка',
            'description' => 'Игра для всех уровней подготовки.',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $response = $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk();
        $html = $response->getContent();
        $scheduleSection = explode('<section class="panel app-venue-block" id="app-venue-schedule">', $html, 2)[1] ?? '';
        $scheduleSection = explode('<section class="panel app-venue-block" id="app-venue-about">', $scheduleSection, 2)[0];

        $this->assertStringContainsString('<h2>Расписание</h2>', $scheduleSection);
        $this->assertStringNotContainsString('<h2>Часы работы</h2>', $scheduleSection);
        $this->assertStringContainsString('09:00–21:00', $scheduleSection);
        $this->assertStringContainsString('12:00–14:00', $scheduleSection);
        $this->assertStringContainsString('Исключение: выходной', $scheduleSection);
        $this->assertStringContainsString('Исключение: изменённые часы', $scheduleSection);
        $this->assertStringNotContainsString('19:00–21:00', $scheduleSection);
        $this->assertStringNotContainsString('data-venue-slot-open', $scheduleSection);
        $this->assertStringNotContainsString('Слоты', $scheduleSection);
        $response->assertSee('Открытая тренировка');
    }

    public function test_public_booked_event_card_shows_type_description_and_canonical_status_counts(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        $event = Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Баскетбольная тренировка',
            'description' => '<p>Игровая практика и бросковая техника.</p>',
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'participation_confirmation_version' => 2,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        foreach ([
            [EventParticipantStatusEnum::CONFIRMED, 2],
            [EventParticipantStatusEnum::TENTATIVE, 2],
            [EventParticipantStatusEnum::CONFIRMED, 1],
            [EventParticipantStatusEnum::LEFT, 2],
        ] as [$status, $version]) {
            $user = User::factory()->create();
            $event->participants()->create([
                'user_id' => $user->id,
                'role' => EventParticipantRoleEnum::PARTICIPANT,
                'participation_roles' => ['player'],
                'status' => $status,
                'confirmation_version' => $version,
            ]);
        }

        $response = $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk();
        $html = $response->getContent();
        $activitySection = explode('<section class="panel app-venue-block" id="app-venue-activities">', $html, 2)[1] ?? '';
        $activitySection = explode('<section class="panel app-venue-block" id="app-venue-interest">', $activitySection, 2)[0];

        $this->assertStringContainsString('Баскетбольная тренировка', $activitySection);
        $this->assertStringContainsString('Тренировка', $activitySection);
        $this->assertStringContainsString('Игровая практика и бросковая техника.', $activitySection);
        $this->assertStringNotContainsString('<p>Игровая практика', $activitySection);
        $this->assertStringContainsString('19:00–21:00', $activitySection);
        $this->assertStringContainsString('Подтвердили: <b>1</b>', $activitySection);
        $this->assertStringContainsString('Думают: <b>1</b>', $activitySection);
        $this->assertStringContainsString('data-venue-slot-open', $activitySection);
        $this->assertStringContainsString(route('events.show', $event->routeIdentifier()), $activitySection);
    }

    public function test_private_event_booking_is_listed_without_private_title_description_or_counts(): void
    {
        [$venue, $booking, $actorId] = $this->venueAndBooking();
        Event::factory()->create([
            'venue_id' => $venue->id,
            'booking_id' => $booking->id,
            'organizer_actor_id' => $actorId,
            'title' => 'Секретная тренировка',
            'description' => 'Закрытое содержание',
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 20:00:00',
        ]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('Время занято')
            ->assertSee('data-venue-slot-open', false)
            ->assertDontSee('Секретная тренировка')
            ->assertDontSee('Закрытое содержание')
            ->assertDontSee('Подтвердили:')
            ->assertDontSee('Думают:');
    }

    /** @return array{0: Venue, 1: VenueBooking, 2: int} */
    private function venueAndBooking(): array
    {
        $organizer = User::factory()->create();
        $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'type' => VenueTypeEnum::SCHOOL,
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $booking = VenueBooking::query()->create([
            'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => '2026-09-12 19:00:00',
            'ends_at' => '2026-09-12 21:00:00',
        ]);

        return [$venue, $booking, $actor->id];
    }
}
