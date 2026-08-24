<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventBus;
use Tests\TestCase;

final class GameAdmissionNotificationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_notification_is_linked_to_admission_and_resolved_after_decision(): void
    {
        EventBus::fake([EventChanged::class]);

        $organizer = User::factory()->create(['username' => 'notification-organizer']);
        $player = User::factory()->create(['username' => 'notification-player']);
        $organizerActor = app(CurrentActorResolver::class)->resolve($organizer, null);
        $this->assertNotNull($organizerActor);

        $event = Event::factory()->create([
            'organizer_actor_id' => $organizerActor->id,
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(3),
        ]);
        $game = Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $organizerActor->id,
            'status' => GameStatusEnum::SCHEDULED,
            'recruitment_mode' => GameRecruitmentModeEnum::INDIVIDUAL_DRAFT,
            'accepts_applications' => true,
            'format' => GameFormatEnum::STREETBALL_3X3,
            'timing_mode' => GameTimingModeEnum::WHOLE_GAME,
            'side_a_size' => 3,
            'side_b_size' => 3,
            'scoring_type' => GameScoringTypeEnum::STREETBALL,
        ]);
        $event->forceFill(['primary_game_id' => $game->id])->save();
        $route = [$event->routeIdentifier(), $game->id];

        $this->actingAs($player)
            ->postJson(route('events.games.recruitment.apply', $route))
            ->assertOk();

        $admission = $game->admissions()->where('user_id', $player->id)->firstOrFail();
        $notification = UserNotification::query()
            ->where('user_id', $organizer->id)
            ->where('title', 'Новая заявка на игру')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admission->id, (int) ($notification->payload['game_admission_id'] ?? 0));
        $this->assertSame(
            $admission->id,
            (int) app(UserNotificationPresenter::class)->present($notification)['context']['game_admission_id'],
        );

        $this->actingAs($organizer)
            ->postJson(route('events.games.recruitment.respond', [...$route, $admission->id]), [
                'decision' => 'accepted',
            ])
            ->assertOk();

        $notification->refresh();
        $this->assertSame(UserNotificationStatusEnum::READ, $notification->status);
        $this->assertNotNull($notification->read_at);
        EventBus::assertDispatched(EventChanged::class, fn (EventChanged $changed): bool => $changed->eventId === $event->id);
    }
}
