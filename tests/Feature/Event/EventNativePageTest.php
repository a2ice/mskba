<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EventNativePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        View::getFinder()->flush();
    }

    private function createEvent(User $user, Venue $venue, array $extra = []): Event
    {
        $this->actingAs($user)->postJson(route('events.funnel.store'), [
            'event_request_id' => (string) Str::uuid(),
            'venue_id' => $venue->id,
            'type' => 'training',
            'title' => 'Новая встреча',
            'visibility' => 'public',
            'slots' => [],
            ...$extra,
        ])->assertCreated();

        return Event::query()->latest('id')->firstOrFail();
    }

    private function venue(): Venue
    {
        return Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
            'raw_address' => 'Тестовый адрес площадки',
        ]);
    }

    public function test_undated_page_keeps_organizer_and_discussion_and_does_not_book(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $venue->courts()->first() ?? $venue->courts()->create([
            'name' => 'Основной зал', 'alias' => 'court-main', 'is_primary' => true, 'sort_order' => 10,
        ]);
        $event = $this->createEvent($user, $venue);
        $url = route('events.show', $event->routeIdentifier());

        $this->assertNull($event->venue_court_id);
        $this->get($url)->assertOk()
            ->assertSee('data-native-event-page', false)
            ->assertSee('Мероприятие сохранено как черновик')
            ->assertSee('Любой зал')
            ->assertSee($venue->name)
            ->assertSee('Посмотреть на карте')
            ->assertSee('Посмотреть занятость площадки')
            ->assertSee('Организатор')
            ->assertSee('Обсуждение и история')
            ->assertSee('data-event-wall-form', false)
            ->assertSee('Указать дату и время')
            ->assertDontSee('id="event-booking"', false);

        $this->patchJson(route('events.participation', $event->routeIdentifier()), ['status' => 'left'])->assertOk();
        $organizer = $event->participants()->firstOrFail();
        $this->assertSame(EventParticipantRoleEnum::ORGANIZER, $organizer->role);
        $this->assertSame(EventParticipantStatusEnum::LEFT, $organizer->status);
        $this->get($url)->assertOk()->assertSee('Не пойдёт')->assertSee('data-event-wall-form', false);

        $this->get(route('events.edit', $event->routeIdentifier()))->assertOk();
        $this->put(route('events.update', $event->routeIdentifier()), [
            'title' => 'Черновик изменён', 'type' => 'training', 'visibility' => 'public',
            'description' => 'Тест', 'max_participants' => 10,
        ])->assertRedirect();
        $this->assertNull($event->fresh()->starts_at);
        $this->assertSame(EventStatusEnum::DRAFT, $event->fresh()->status);
        $this->assertDatabaseCount('venue_bookings', 0);

        $this->actingAs(User::factory()->create(['status' => UserStatusEnum::CONFIRMED]))
            ->get($url)->assertNotFound();
    }

    public function test_published_event_uses_nonprimary_selected_hall_and_booking_popup(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = $this->venue();
        $venue->courts()->first() ?? $venue->courts()->create([
            'name' => 'Основной зал', 'alias' => 'court-main', 'is_primary' => true, 'sort_order' => 10,
        ]);
        $court = $venue->courts()->create([
            'name' => 'Испытательный зал', 'alias' => 'court-test', 'is_primary' => false, 'sort_order' => 20,
        ]);
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(2)->setTime(19, 0);
        $event = $this->createEvent($owner, $venue, [
            'venue_court_id' => $court->id,
            'slots' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'duration_minutes' => 90]],
        ]);
        $this->assertSame($court->id, $event->venue_court_id);

        $booking = $event->booking()->create([
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => VenueBookingStatusEnum::CONFIRMED,
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
        ]);
        $this->assertSame($court->id, $booking->venue_court_id);
        $reader = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($reader)->get(route('events.show', $event->routeIdentifier()))
            ->assertOk()->assertSee('data-native-event-page', false)
            ->assertSee('Испытательный зал')
            ->assertSee('19:00')
            ->assertSee('data-event-dialog-open="event-booking"', false)
            ->assertSee('id="event-booking"', false)
            ->assertSee('Бронирование №'.$booking->id)
            ->assertDontSee('Пригласить участников')
            ->assertDontSee('Перейти к заявке');
    }
}
