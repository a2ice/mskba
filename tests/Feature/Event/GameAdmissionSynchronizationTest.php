<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

final class GameAdmissionSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_and_decision_publish_event_change_and_resolve_original_notification(): void
    {
        $organizer = $this->confirmedUser('sync-organizer');
        $player = $this->confirmedUser('sync-player');
        $game = $this->createStandaloneGame($organizer);
        $route = [$game->event->routeIdentifier(), $game->id];

        EventFacade::fake([EventChanged::class]);
        $this->actingAs($player)
            ->postJson(route('events.games.recruitment.apply', $route))
            ->assertOk();

        $admission = $game->admissions()->where('user_id', $player->id)->firstOrFail();
        $notification = UserNotification::query()
            ->where('user_id', $organizer->id)
            ->where('title', 'Новая заявка на игру')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admission->id, (int) $notification->payload['game_admission_id']);
        $this->assertSame(UserNotificationStatusEnum::NEW, $notification->status);
        $this->assertSame(
            $admission->id,
            app(UserNotificationPresenter::class)->present($notification)['context']['game_admission_id'],
        );
        EventFacade::assertDispatched(
            EventChanged::class,
            fn (EventChanged $changed): bool => $changed->eventId === $game->event_id,
        );

        EventFacade::fake([EventChanged::class]);
        $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
                'decision' => GameAdmissionStatusEnum::ACCEPTED->value,
            ])
            ->assertOk();

        $this->assertSame(GameAdmissionStatusEnum::ACCEPTED, $admission->refresh()->status);
        $this->assertSame(UserNotificationStatusEnum::READ, $notification->refresh()->status);
        EventFacade::assertDispatched(
            EventChanged::class,
            fn (EventChanged $changed): bool => $changed->eventId === $game->event_id,
        );
    }

    private function createStandaloneGame(User $organizer)
    {
        [$venue, $start] = $this->availableVenue();
        $payload = [
            'venue_id' => $venue->id,
            'title' => 'Admission synchronization game',
            'type' => EventTypeEnum::GAME->value,
            'visibility' => 'public',
            'description' => null,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'duration_minutes' => 90,
            'max_participants' => 20,
            'game_recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT->value,
            'game_accepts_applications' => true,
            'game_format' => 'streetball_1x1',
            'side_a_size' => 1,
            'side_b_size' => 1,
            'scoring_type' => 'streetball',
            'timing_mode' => 'whole_game',
            'publish_to_telegram' => false,
        ];

        $this->actingAs($organizer)->post(route('events.store'), $payload)->assertRedirect();

        return Event::query()
            ->where('title', $payload['title'])
            ->firstOrFail()
            ->primaryGame()
            ->firstOrFail()
            ->load('event');
    }

    private function confirmedUser(string $username): User
    {
        return User::factory()->create([
            'username' => $username,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    /** @return array{Venue, CarbonImmutable} */
    private function availableVenue(): array
    {
        $start = CarbonImmutable::now('Europe/Moscow')->addDays(7)->setTime(12, 0);
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED->value,
            'requires_payment' => false,
            'requires_booking_approval' => false,
        ]);
        $schedule = VenueSchedule::factory()->for($venue)->create(['timezone' => 'Europe/Moscow']);
        VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
            'day_of_week' => $start->isoWeekday(),
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'sort_order' => 0,
        ]);

        return [$venue, $start];
    }
}
