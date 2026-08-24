<?php

namespace Tests\Feature\Telegram;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameRosterStatusEnum;
use App\Modules\Event\Domain\Enums\GameScoringTypeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Enums\GameTimingModeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GamePlayerStatistic;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\Services\TelegramEventMessageBuilder;
use App\Modules\Telegram\Application\Services\TelegramMiniAppStartDestinationResolver;
use App\Modules\Telegram\Application\UseCases\HandleEventParticipationCallback;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramEventParticipationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.api_ip' => null,
            'telegram.http_proxy' => null,
        ]);
    }

    public function test_individual_game_card_shows_context_and_personal_participation_buttons(): void
    {
        $this->travelTo(now()->setTimezone('Europe/Moscow')->startOfDay()->addHours(12));

        [$event, $game] = $this->standaloneGame();
        $player = User::factory()->create();
        $actor = app(CurrentActorResolver::class)->resolve($player, null);
        $game->admissions()->create([
            'candidate_type' => GameAdmissionCandidateTypeEnum::USER,
            'user_id' => $player->id,
            'direction' => GameAdmissionDirectionEnum::APPLICATION,
            'status' => GameAdmissionStatusEnum::PENDING,
            'requested_by_actor_id' => $actor?->id,
        ]);

        $event = $event->fresh([
            'venue.schedule',
            'participants.user.profile',
            'primaryGame.sides',
            'primaryGame.admissions.user',
            'games.sides',
        ]);
        $builder = app(TelegramEventMessageBuilder::class);
        $text = $builder->text($event);
        $buttons = $builder->replyMarkup($event)['inline_keyboard'];

        $this->assertStringContainsString('Формат: Стритбол 3×3', $text);
        $this->assertStringContainsString('Набор: Отдельные игроки', $text);
        $this->assertStringContainsString('Набор игроков: принято 0 · на рассмотрении 1', $text);
        $this->assertStringContainsString('📝 Описание:', $text);
        $this->assertStringContainsString('🗓 Сегодня,', $text);
        $this->assertStringNotContainsString('(МСК)', $text);
        $this->assertStringNotContainsString('👥 Участники:', $text);
        $this->assertSame("event:{$event->id}:join", $buttons[0][0]['callback_data']);
        $this->assertSame("event:{$event->id}:leave", $buttons[0][1]['callback_data']);
    }

    public function test_preformed_team_game_has_no_personal_participation_buttons(): void
    {
        [$event] = $this->standaloneGame(GameRecruitmentModeEnum::PREFORMED_TEAMS);
        $event = $event->fresh([
            'venue.schedule',
            'participants.user.profile',
            'primaryGame.sides',
            'primaryGame.admissions.team',
            'games.sides',
        ]);

        $encoded = json_encode(
            app(TelegramEventMessageBuilder::class)->replyMarkup($event),
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString("event:{$event->id}:join", $encoded);
        $this->assertStringNotContainsString("event:{$event->id}:leave", $encoded);
        $this->assertStringContainsString('startapp=event_', $encoded);
    }

    public function test_telegram_join_creates_pending_game_admission_not_confirmed_event_participant(): void
    {
        $this->fakeTelegramAnswer();
        [$event, $game] = $this->standaloneGame();
        $user = $this->telegramUser();
        $this->publication($event);

        app(HandleEventParticipationCallback::class)
            ->handle($this->callbackPayload($event->id, 'join'));

        $this->assertDatabaseHas('game_admissions', [
            'game_id' => $game->id,
            'candidate_type' => GameAdmissionCandidateTypeEnum::USER->value,
            'user_id' => $user->id,
            'direction' => GameAdmissionDirectionEnum::APPLICATION->value,
            'status' => GameAdmissionStatusEnum::PENDING->value,
        ]);
        $this->assertDatabaseMissing('event_participants', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventParticipantStatusEnum::CONFIRMED->value,
        ]);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Заявка отправлена организатору'));
    }

    public function test_telegram_leave_after_acceptance_excludes_player_but_keeps_existing_statistics(): void
    {
        $this->fakeTelegramAnswer();
        [$event, $game] = $this->standaloneGame();
        $user = $this->telegramUser();
        $actor = app(CurrentActorResolver::class)->resolve($user, null);
        $admission = $game->admissions()->create([
            'candidate_type' => GameAdmissionCandidateTypeEnum::USER,
            'user_id' => $user->id,
            'direction' => GameAdmissionDirectionEnum::APPLICATION,
            'status' => GameAdmissionStatusEnum::ACCEPTED,
            'requested_by_actor_id' => $actor?->id,
            'responded_by_actor_id' => $actor?->id,
            'responded_at' => now(),
        ]);
        $participant = $event->participants()->create([
            'user_id' => $user->id,
            'role' => EventParticipantRoleEnum::PARTICIPANT,
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'joined_at' => now(),
            'confirmation_version' => $event->participation_confirmation_version,
        ]);
        $sideA = $game->sides()->create(['slot' => 'A', 'display_name' => 'Оранжевые']);
        $game->sides()->create(['slot' => 'B', 'display_name' => 'Чёрные']);
        $roster = $game->rosterEntries()->create([
            'game_side_id' => $sideA->id,
            'user_id' => $user->id,
            'source_event_participant_id' => $participant->id,
            'status' => GameRosterStatusEnum::SELECTED,
        ]);
        $statistic = GamePlayerStatistic::query()->create([
            'game_id' => $game->id,
            'game_side_id' => $sideA->id,
            'user_id' => $user->id,
            'close_made' => 2,
            'close_attempted' => 3,
        ]);
        $game->forceFill([
            'status' => GameStatusEnum::IN_PROGRESS,
            'sides_confirmed_at' => now(),
            'actual_started_at' => now(),
        ])->save();
        $this->publication($event);

        app(HandleEventParticipationCallback::class)
            ->handle($this->callbackPayload($event->id, 'leave'));

        $this->assertSame(GameAdmissionStatusEnum::REVOKED, $admission->refresh()->status);
        $this->assertSame(EventParticipantStatusEnum::LEFT, $participant->refresh()->status);
        $this->assertSame(GameRosterStatusEnum::EXCLUDED, $roster->refresh()->status);
        $this->assertDatabaseHas('game_player_statistics', [
            'id' => $statistic->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
            'close_made' => 2,
            'close_attempted' => 3,
        ]);
    }

    public function test_cancelled_public_game_still_opens_from_telegram_mini_app_destination(): void
    {
        [$event, $game] = $this->standaloneGame();
        $event->forceFill([
            'status' => EventStatusEnum::CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Дождь',
        ])->save();
        $game->forceFill([
            'status' => GameStatusEnum::CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Родительское мероприятие отменено.',
        ])->save();

        $destination = app(TelegramMiniAppStartDestinationResolver::class)
            ->resolve("event_{$event->id}");

        $this->assertSame(route('events.show', $event->routeIdentifier(), false), $destination);
        $this->get($destination)
            ->assertOk()
            ->assertSee('Отменена');

        $encoded = json_encode(
            app(TelegramEventMessageBuilder::class)->replyMarkup(
                $event->fresh([
                    'venue.schedule',
                    'primaryGame.sides',
                    'primaryGame.admissions.user',
                    'games.sides',
                ]),
            ),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString("event:{$event->id}:join", $encoded);
        $this->assertStringNotContainsString("event:{$event->id}:leave", $encoded);
        $this->assertStringContainsString('startapp=event_', $encoded);
    }

    /** @return array{Event, Game} */
    private function standaloneGame(
        GameRecruitmentModeEnum $mode = GameRecruitmentModeEnum::INDIVIDUAL_DRAFT,
    ): array {
        $event = Event::factory()->create([
            'type' => EventTypeEnum::GAME,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(3),
        ]);
        $game = Game::query()->create([
            'event_id' => $event->id,
            'created_by_actor_id' => $event->organizer_actor_id,
            'status' => GameStatusEnum::SCHEDULED,
            'recruitment_mode' => $mode,
            'accepts_applications' => true,
            'format' => GameFormatEnum::STREETBALL_3X3,
            'timing_mode' => GameTimingModeEnum::WHOLE_GAME,
            'side_a_size' => 3,
            'side_b_size' => 3,
            'scoring_type' => GameScoringTypeEnum::STREETBALL,
        ]);
        $event->forceFill(['primary_game_id' => $game->id])->save();

        return [$event->refresh(), $game->refresh()];
    }

    private function telegramUser(): User
    {
        $user = User::factory()->create(['username' => 'telegram-player']);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
        ]);

        return $user;
    }

    private function publication(Event $event): void
    {
        TelegramEventPublication::query()->create([
            'event_id' => $event->id,
            'chat_id' => '-1002136558099',
            'message_id' => 501,
            'status' => 'published',
        ]);
    }

    private function fakeTelegramAnswer(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function callbackPayload(int $eventId, string $action): array
    {
        return [
            'id' => 'callback-1',
            'from' => [
                'id' => 777,
                'username' => 'telegram-player',
                'first_name' => 'Telegram',
                'last_name' => 'Player',
                'language_code' => 'ru',
            ],
            'message' => [
                'message_id' => 501,
                'chat' => ['id' => -1002136558099],
            ],
            'data' => "event:{$eventId}:{$action}",
        ];
    }
}
