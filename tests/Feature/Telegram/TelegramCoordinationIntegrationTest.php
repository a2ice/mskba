<?php

namespace Tests\Feature\Telegram;

use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\UseCases\HandleCoordinationVoteCallback;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramCoordinationPublication;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramCoordinationPublicationJob;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramCoordinationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.main_chat_id' => null,
        ]);
    }

    public function test_configured_main_chat_is_registered_for_coordination(): void
    {
        config(['telegram.main_chat_id' => '-1009001']);

        $this->actingAs(User::factory()->create())
            ->get(route('coordination.create'))
            ->assertOk()
            ->assertSee('Основной чат MSKBA');

        $this->assertDatabaseHas('telegram_chats', [
            'telegram_chat_id' => -1009001,
            'is_active' => true,
            'publishes_coordination' => true,
        ]);
    }

    public function test_creator_publishes_poll_to_each_selected_chat(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $firstChat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);
        $secondChat = TelegramChat::query()->create([
            'telegram_chat_id' => -1002,
            'title' => 'Север',
        ]);

        $this->actingAs($user)->post(route('coordination.store'), [
            ...$this->payload(),
            'publish_to_telegram' => '1',
            'telegram_chat_ids' => [$firstChat->id, $secondChat->id],
        ])->assertRedirect();

        $poll = CoordinationSession::query()->firstOrFail()->polls()->firstOrFail();
        $this->assertDatabaseHas('telegram_coordination_publications', [
            'poll_id' => $poll->id,
            'chat_id' => $firstChat->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('telegram_coordination_publications', [
            'poll_id' => $poll->id,
            'chat_id' => $secondChat->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(SyncTelegramCoordinationPublicationJob::class, 2);
    }

    public function test_publication_job_sends_poll_with_inline_vote_and_mini_app_actions(): void
    {
        $session = $this->createSession();
        $poll = $session->polls()->firstOrFail();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);
        $publication = TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
        ]);
        $option = $poll->options()->firstOrFail();

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);

        app()->call([new SyncTelegramCoordinationPublicationJob($publication->id), 'handle']);

        $this->assertDatabaseHas('telegram_coordination_publications', [
            'id' => $publication->id,
            'message_id' => 501,
            'status' => 'published',
        ]);
        Http::assertSent(function ($request) use ($poll, $option): bool {
            $keyboard = $request['reply_markup']['inline_keyboard'];

            return str_contains($request['text'], '<b>Игра вечером</b>')
                && ! str_contains($request['text'], '1. 19:00')
                && $keyboard[0][0]['text'] === '19:00'
                && $keyboard[0][0]['callback_data'] === "coord:{$poll->id}:vote:{$option->id}"
                && $keyboard[array_key_last($keyboard)][0]['url']
                    === "https://t.me/MSKBABot?startapp=coordination_{$poll->session_id}";
        });
    }

    public function test_open_poll_with_always_visible_results_updates_counts_and_keeps_vote_buttons(): void
    {
        Queue::fake();
        $session = $this->createSession();
        $poll = $session->polls()->firstOrFail();
        $poll->forceFill([
            'results_visibility' => PollResultsVisibilityEnum::ALWAYS,
        ])->save();
        $option = $poll->options()->firstOrFail();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);
        $publication = TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'status' => 'published',
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
            'https://api.telegram.org/bot123456:test-token/editMessageText' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);

        app(HandleCoordinationVoteCallback::class)->handle([
            'id' => 'callback-visible-results',
            'from' => [
                'id' => 778,
                'username' => 'visible_voter',
                'first_name' => 'Видимый',
            ],
            'message' => [
                'message_id' => 501,
                'chat' => ['id' => -1001],
            ],
            'data' => "coord:{$poll->id}:vote:{$option->id}",
        ]);

        app()->call([new SyncTelegramCoordinationPublicationJob($publication->id), 'handle']);

        Http::assertSent(function ($request) use ($poll, $option): bool {
            if (! str_ends_with($request->url(), '/editMessageText')) {
                return false;
            }

            $keyboard = $request['reply_markup']['inline_keyboard'];

            return str_contains($request['text'], '• 19:00 — <b>1</b>')
                && str_contains($request['text'], "Видимый\n\n")
                && $keyboard[0][0]['text'] === '19:00 (1)'
                && $keyboard[0][0]['callback_data'] === "coord:{$poll->id}:vote:{$option->id}";
        });
    }

    public function test_attendance_poll_message_shows_venue_and_event_time(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Школа №1794',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $startsAt = CarbonImmutable::now('Europe/Moscow')->addDay()->setTime(19, 0);

        $this->actingAs(User::factory()->create())
            ->post(route('coordination.store'), [
                'flow_type' => 'event_attendance',
                'title' => 'Собираем состав',
                'description' => 'Проверяем, кто сможет прийти.',
                'fixed_venue_id' => $venue->id,
                'fixed_starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'event_duration_minutes' => 90,
                'going_label' => 'Пойду',
                'not_going_label' => 'Не пойду',
                'include_thinking_option' => '1',
                'thinking_label' => 'Думаю',
                'results_visibility' => 'always',
                'allows_vote_changes' => '0',
                'is_anonymous' => '0',
                'allows_suggestions' => '0',
                'publish_to_telegram' => '0',
                'closes_at' => CarbonImmutable::now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $poll = CoordinationSession::query()->latest('id')->firstOrFail()->polls()->firstOrFail();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);
        $publication = TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 502],
            ]),
        ]);

        app()->call([new SyncTelegramCoordinationPublicationJob($publication->id), 'handle']);

        $expectedEventTime = '🗓 '.$startsAt->locale('ru')->translatedFormat('j F').' 19:00–20:30';

        Http::assertSent(fn ($request): bool => str_contains(
            $request['text'],
            '📍 <b>Школа №1794</b>',
        ) && ! str_contains(
            $request['text'],
            'Вы сможете прийти?',
        ) && str_contains(
            $request['text'],
            $expectedEventTime,
        ) && str_contains(
            $request['text'],
            "⇢ Пойду — <b>0</b>\n\n",
        ) && str_contains(
            $request['text'],
            "⇢ Не пойду — <b>0</b>\n\n",
        ) && str_contains(
            $request['text'],
            "⇢ Думаю — <b>0</b>\n\n",
        ) && $request['reply_markup']['inline_keyboard'][0][0]['text'] === 'Пойду (0)'
            && $request['reply_markup']['inline_keyboard'][1][0]['text'] === 'Не пойду (0)'
            && $request['reply_markup']['inline_keyboard'][2][0]['text'] === 'Думаю (0)'
            && ! str_contains(
                $request['text'],
                '(МСК)',
            ));
    }

    public function test_expired_poll_is_closed_and_telegram_message_is_queued_for_update(): void
    {
        Queue::fake();
        $session = $this->createSession();
        $poll = $session->polls()->firstOrFail();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);
        $publication = TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'status' => 'published',
        ]);
        $poll->forceFill(['closes_at' => now()->subMinute()])->save();

        $this->artisan('coordination:close-expired')->assertSuccessful();

        $this->assertSame(PollStatusEnum::CLOSED, $poll->fresh()->status);
        Queue::assertPushed(
            SyncTelegramCoordinationPublicationJob::class,
            fn (SyncTelegramCoordinationPublicationJob $job): bool => $job->publicationId === $publication->id,
        );
    }

    public function test_closed_poll_message_shows_closed_status_and_results_without_vote_buttons(): void
    {
        $session = $this->createSession();
        $poll = $session->polls()->firstOrFail();
        $poll->forceFill([
            'status' => PollStatusEnum::CLOSED,
            'closed_at' => now(),
        ])->save();
        $chat = TelegramChat::query()->create([
            'telegram_chat_id' => -1001,
            'title' => 'Основной',
        ]);
        $publication = TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'status' => 'published',
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/editMessageText' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);

        app()->call([new SyncTelegramCoordinationPublicationJob($publication->id), 'handle']);

        Http::assertSent(function ($request): bool {
            $keyboard = $request['reply_markup']['inline_keyboard'];

            return str_contains($request['text'], 'Статус: <b>Закрыт</b>')
                && str_contains($request['text'], '• 19:00 — <b>0</b>')
                && count($keyboard) === 1
                && $keyboard[0][0]['text'] === '🏀 Открыть опрос';
        });
        $this->assertSame('closed', $publication->fresh()->status);
    }

    public function test_chat_callback_creates_user_and_saves_vote_idempotently(): void
    {
        Queue::fake();
        $session = $this->createSession();
        $poll = $session->polls()->firstOrFail();
        $option = $poll->options()->firstOrFail();
        $chat = TelegramChat::query()->create(['telegram_chat_id' => -1001]);
        TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'status' => 'published',
        ]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
        $callback = [
            'id' => 'callback-1',
            'from' => [
                'id' => 777,
                'username' => 'new_voter',
                'first_name' => 'Новый',
            ],
            'message' => [
                'message_id' => 501,
                'chat' => ['id' => -1001],
            ],
            'data' => "coord:{$poll->id}:vote:{$option->id}",
        ];

        app(HandleCoordinationVoteCallback::class)->handle($callback);
        app(HandleCoordinationVoteCallback::class)->handle([
            ...$callback,
            'id' => 'callback-2',
        ]);

        $this->assertDatabaseHas('telegram_accounts', ['telegram_user_id' => 777]);
        $this->assertDatabaseCount('coordination_ballots', 1);
        $this->assertDatabaseCount('coordination_ballot_selections', 1);
        $this->assertDatabaseHas('coordination_ballot_selections', ['option_id' => $option->id]);
        Http::assertSentCount(2);
    }

    public function test_chat_callback_rejects_multiple_choice_poll(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create())
            ->post(route('coordination.store'), [
                ...$this->payload(),
                'selection_mode' => 'multiple',
            ])
            ->assertRedirect();
        $poll = CoordinationSession::query()->firstOrFail()->polls()->firstOrFail();
        $option = $poll->options()->firstOrFail();
        $chat = TelegramChat::query()->create(['telegram_chat_id' => -1001]);
        TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'status' => 'published',
        ]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        app(HandleCoordinationVoteCallback::class)->handle([
            'id' => 'callback-1',
            'from' => ['id' => 777, 'first_name' => 'Новый'],
            'message' => ['message_id' => 501, 'chat' => ['id' => -1001]],
            'data' => "coord:{$poll->id}:vote:{$option->id}",
        ]);

        $this->assertDatabaseCount('coordination_ballots', 0);
        Http::assertSent(fn ($request): bool => $request['text'] === 'Выберите варианты в Mini App.'
            && $request['show_alert'] === true);
    }

    public function test_unchanged_telegram_message_is_an_idempotent_success(): void
    {
        $session = $this->createSession();
        $poll = $session->polls()->firstOrFail();
        $chat = TelegramChat::query()->create(['telegram_chat_id' => -1001]);
        $publication = TelegramCoordinationPublication::query()->create([
            'poll_id' => $poll->id,
            'chat_id' => $chat->id,
            'message_id' => 501,
            'status' => 'published',
        ]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/editMessageText' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message is not modified',
            ], 400),
        ]);

        app()->call([new SyncTelegramCoordinationPublicationJob($publication->id), 'handle']);

        $this->assertSame('published', $publication->fresh()->status);
        $this->assertNull($publication->fresh()->last_error);
        $this->assertNotNull($publication->fresh()->synced_at);
    }

    public function test_admin_manages_coordination_chats(): void
    {
        Queue::fake();
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.telegram-chats.store'), [
                'telegram_chat_id' => -1002003,
                'title' => 'Северный чат',
            ])
            ->assertRedirect();

        $chat = TelegramChat::query()->where('telegram_chat_id', -1002003)->firstOrFail();
        $this->actingAs($admin)
            ->get(route('admin.telegram-chats'))
            ->assertOk()
            ->assertSee('Северный чат');
        $this->actingAs($admin)
            ->put(route('admin.telegram-chats.update', $chat), [
                'title' => 'Северный чат',
                'is_active' => '0',
                'publishes_coordination' => '0',
                'publishes_events' => '1',
            ])
            ->assertRedirect();

        $this->assertFalse($chat->fresh()->is_active);
        $this->assertFalse($chat->fresh()->publishes_coordination);
        $this->assertTrue($chat->fresh()->publishes_events);
    }

    private function createSession(): CoordinationSession
    {
        $this->actingAs(User::factory()->create())
            ->post(route('coordination.store'), $this->payload())
            ->assertRedirect();

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
            'publish_to_telegram' => '0',
            'closes_at' => CarbonImmutable::now()->addDay()->format('Y-m-d H:i:s'),
            'options' => ['19:00', '20:00', '21:00'],
        ];
    }
}
