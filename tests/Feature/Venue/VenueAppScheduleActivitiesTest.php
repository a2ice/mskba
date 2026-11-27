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
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
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
        $shift->intervals()->create([
            'starts_at' => '17:00',
            'ends_at' => '20:00',
            'sort_order' => 2,
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
        $scheduleSection = explode('</section>', $scheduleSection, 2)[0];

        $this->assertStringContainsString('<h2>Расписание</h2>', $scheduleSection);
        $this->assertStringNotContainsString('Онлайн-бронирование недоступно.', $scheduleSection);
        $this->assertStringNotContainsString('<h2>Часы работы</h2>', $scheduleSection);
        $this->assertStringContainsString('09:00–21:00', $scheduleSection);
        // Informational hours preserve breaks when online rental is disabled.
        $this->assertStringNotContainsString('12:00–20:00', $scheduleSection);
        $this->assertStringContainsString('12:00–14:00', $scheduleSection);
        $this->assertStringContainsString('17:00–20:00', $scheduleSection);
        $this->assertStringNotContainsString('Исключение:', $scheduleSection);
        $this->assertStringNotContainsString('19:00–21:00', $scheduleSection);
        $this->assertStringNotContainsString('data-venue-slot-open', $scheduleSection);
        $this->assertStringNotContainsString('data-venue-day-open', $scheduleSection);
        $this->assertStringNotContainsString('data-venue-rental-open', $scheduleSection);
        $this->assertStringNotContainsString('data-day-occupancy', $scheduleSection);
        $this->assertStringNotContainsString('Слоты', $scheduleSection);
        $response->assertSee('Открытая тренировка');
    }

    public function test_venue_overview_shows_separate_court_schedules_but_court_page_shows_only_its_own(): void
    {
        [$venue] = $this->venueAndBooking();
        $first = $venue->primaryCourt()->firstOrFail();
        $second = $venue->courts()->create([
            'name' => 'Второй зал',
            'alias' => 'second-hall',
            'sort_order' => 20,
            'allows_whole' => true,
        ]);
        $first->schedule()->create([
            'weekly_intervals' => [
                ['day_of_week' => 2, 'starts_at' => '08:00', 'ends_at' => '12:00'],
            ],
        ]);
        $second->schedule()->create([
            'weekly_intervals' => [
                ['day_of_week' => 2, 'starts_at' => '15:00', 'ends_at' => '22:00'],
            ],
        ]);

        $response = $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk();
        $html = $response->getContent();
        $section = explode('id="app-venue-schedule">', $html, 2)[1] ?? '';
        $section = explode('</section>', $section, 2)[0];
        $this->assertSame(2, substr_count($section, 'data-venue-schedule-court="'));
        $this->assertStringContainsString('data-venue-schedule-court="'.$first->id.'"', $section);
        $this->assertStringContainsString('data-venue-schedule-court="'.$second->id.'"', $section);
        $firstGroup = explode('data-venue-schedule-court="'.$first->id.'"', $section, 2)[1] ?? '';
        $firstGroup = explode('data-venue-schedule-court="'.$second->id.'"', $firstGroup, 2)[0];
        $secondGroup = explode('data-venue-schedule-court="'.$second->id.'"', $section, 2)[1] ?? '';
        $this->assertStringContainsString('08:00–12:00', $firstGroup);
        $this->assertStringNotContainsString('15:00–22:00', $firstGroup);
        $this->assertStringContainsString('15:00–22:00', $secondGroup);
        $this->assertStringNotContainsString('08:00–12:00', $secondGroup);
        $this->assertStringNotContainsString('data-venue-day-open', $secondGroup);

        $detail = $this->get(route('venues.courts.show', [
            $venue->routeIdentifier(), $second->routeIdentifier(),
        ]))->assertOk()->getContent();
        $courtSchedule = explode('id="app-venue-schedule">', $detail, 2)[1] ?? '';
        $courtSchedule = explode('</section>', $courtSchedule, 2)[0];
        $this->assertSame(1, substr_count($courtSchedule, 'data-venue-schedule-court="'));
        $this->assertStringContainsString('15:00–22:00', $courtSchedule);
        $this->assertStringNotContainsString('08:00–12:00', $courtSchedule);
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
            ->assertSee('Мероприятие по приглашению')
            ->assertSee('data-venue-slot-open', false)
            ->assertSee('data-has-event="true"', false)
            ->assertDontSee('Секретная тренировка')
            ->assertDontSee('Закрытое содержание')
            ->assertDontSee('Подтвердили:')
            ->assertDontSee('Думают:');
    }

    public function test_booking_without_attached_event_does_not_render_activity_section_or_anchor(): void
    {
        [$venue] = $this->venueAndBooking();

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('id="app-venue-activities"', false)
            ->assertDontSee('href="#app-venue-activities"', false)
            ->assertDontSee('<h2>Игры и мероприятия</h2>', false)
            ->assertSee('Расписание');
    }

    public function test_cashback_is_in_about_section_not_day_dialog_and_respects_reward_toggle(): void
    {
        [$venue] = $this->venueAndBooking();

        $url = route('venues.show', $venue->routeIdentifier());
        $before = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('app-venue-about__promotions', $before);

        $reward = Reward::query()->where('code', 'venue_rental_completed')->firstOrFail();
        // This suite freezes time before the seed migration's default valid_from.
        $reward->currentVersion()->firstOrFail()->update(['valid_from' => Carbon::now()->subDay()]);
        $reward->update(['is_enabled' => true]);
        // A valid reward cannot make a rental promise while booking is disabled.
        $this->get($url)->assertOk()->assertDontSee('app-venue-about__promotions', false);

        config()->set('features.venue_rental.rental_flow', true);
        $court = $venue->primaryCourt()->firstOrFail();
        $court->update(['allows_whole' => true, 'rental_enabled' => true]);
        $policy = VenueBookingPolicy::query()->create([
            'venue_id' => $venue->id,
            'version' => 1,
            'is_enabled' => true,
            'allows_whole' => true,
            'minimum_duration_minutes' => 60,
            'maximum_duration_minutes' => 480,
            'time_step_minutes' => 30,
            'currency' => 'RUB',
            'whole_price_per_step_minor' => 10000,
            'hold_duration_minutes' => 30,
            'requires_payment' => true,
            'payment_window_minutes' => 30,
            'published_by_user_id' => User::factory()->create()->id,
            'published_at' => now(),
            'active_marker' => true,
        ]);
        $unbookable = $venue->courts()->create([
            'name' => 'Без аренды', 'alias' => 'no-rental', 'sort_order' => 20,
            'allows_whole' => true, 'rental_enabled' => false,
        ]);
        $html = $this->get($url)->assertOk()->getContent();
        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $court->routeIdentifier()]))
            ->assertOk()->assertSee('app-venue-about__promotions', false);
        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $unbookable->routeIdentifier()]))
            ->assertOk()->assertDontSee('app-venue-about__promotions', false);
        $about = explode('<section class="panel app-venue-block" id="app-venue-about">', $html, 2)[1] ?? '';
        $about = explode('</section>', $about, 2)[0];
        $dialog = explode('<dialog class="app-venue-overlay app-venue-day-dialog"', $html, 2)[1] ?? '';
        $dialog = explode('</dialog>', $dialog, 2)[0];

        $this->assertStringContainsString('app-venue-about__promotions', $about);
        $this->assertSame(1, substr_count($about, '<strong>Арендуй зал — получи бонусы</strong>'));
        $this->assertStringContainsString('Кешбэк: 100 ₽', $about);
        $this->assertStringNotContainsString('app-venue-day-dialog__promotion', $dialog);
        $this->assertStringNotContainsString('app-venue-day-dialog__info', $dialog);
        $this->assertStringContainsString('data-venue-day-status hidden', $dialog);
        $this->assertStringNotContainsString('<section class="panel app-venue-block app-venue-promotion"', $html);
        $this->assertStringNotContainsString('Рабочие часы площадки и исключения из обычного графика.', $html);
        $this->assertStringNotContainsString('Часы работы не гарантируют доступность бронирования.', $html);

        $promotion = Promotion::query()->where('code', 'reward_venue_rental_completed')->firstOrFail();
        $promotion->placements()->whereIn('placement_code', ['venue_page', 'venue_slots'])
            ->update(['title_override' => 'Вознаграждение за бронирование']);
        $this->get($url)->assertOk()->assertSee('Вознаграждение за бронирование');
        $policy->update(['is_enabled' => false]);
        $this->get($url)->assertOk()->assertDontSee('Вознаграждение за бронирование');
        $policy->update(['is_enabled' => true]);

        $reward->update(['is_enabled' => false]);
        $after = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('app-venue-about__promotions', $after);
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
