<?php

namespace Tests\Feature\Telegram;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\UseCases\HandleEventParticipationCallback;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramCallbackJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramEventPublicationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.main_chat_id' => '-1002136558099',
            'telegram.webhook_secret' => 'webhook-test-secret',
            'telegram.api_ip' => null,
            'telegram.http_proxy' => null,
            'telegram.updates_transport' => 'webhook',
        ]);

        Cache::forget('telegram:updates:offset');
    }

    public function test_public_event_is_published_to_main_chat_with_participation_actions(): void
    {
        $event = Event::factory()->create([
            'title' => 'Игра у метро',
            'max_participants' => 10,
        ]);
        $organizer = User::factory()->create();
        $event->participants()->create([
            'user_id' => $organizer->id,
            'role' => EventParticipantRoleEnum::ORGANIZER,
            'status' => EventParticipantStatusEnum::CONFIRMED,
            'joined_at' => now(),
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);

        app()->call([new SyncTelegramEventPublicationJob($event->id), 'handle']);

        $this->assertDatabaseHas('telegram_event_publications', [
            'event_id' => $event->id,
            'chat_id' => '-1002136558099',
            'message_id' => 501,
            'status' => 'published',
        ]);

        Http::assertSent(function ($request) use ($event): bool {
            $buttons = $request['reply_markup']['inline_keyboard'];

            return $request->url() === 'https://api.telegram.org/bot123456:test-token/sendMessage'
                && $request['chat_id'] === '-1002136558099'
                && str_contains($request['text'], '<b>Игра у метро</b>')
                && str_contains($request['text'], 'Участники: 1/10')
                && $buttons[0][0]['callback_data'] === "event:{$event->id}:join"
                && $buttons[0][1]['callback_data'] === "event:{$event->id}:leave"
                && $buttons[1][0]['url'] === route('events.show', $event->routeIdentifier());
        });
    }

    public function test_webhook_requires_secret_and_queues_callback_processing(): void
    {
        Queue::fake();
        $callback = $this->callbackPayload(10);

        $this->postJson(route('integrations.telegram.webhook'), ['callback_query' => $callback])
            ->assertForbidden();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-test-secret')
            ->postJson(route('integrations.telegram.webhook'), ['callback_query' => $callback])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Queue::assertPushed(
            ProcessTelegramCallbackJob::class,
            fn (ProcessTelegramCallbackJob $job): bool => $job->callback['id'] === 'callback-1',
        );
    }

    public function test_event_change_queues_immediate_sync_and_start_refresh(): void
    {
        Queue::fake();
        $event = Event::factory()->create();

        event(new EventChanged($event->id));

        Queue::assertPushed(
            SyncTelegramEventPublicationJob::class,
            2,
        );
        Queue::assertPushed(
            SyncTelegramEventPublicationJob::class,
            fn (SyncTelegramEventPublicationJob $job): bool => $job->eventId === $event->id
                && $job->delay !== null,
        );
    }

    public function test_linked_telegram_user_can_join_and_leave_event_idempotently(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
        $event = Event::factory()->create(['max_participants' => 2]);
        $user = User::factory()->create();
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
        ]);
        TelegramEventPublication::query()->create([
            'event_id' => $event->id,
            'chat_id' => '-1002136558099',
            'message_id' => 501,
            'status' => 'published',
        ]);

        $join = $this->callbackPayload($event->id, 'join');
        app(HandleEventParticipationCallback::class)->handle($join);
        app(HandleEventParticipationCallback::class)->handle($join);

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventParticipantStatusEnum::CONFIRMED->value,
        ]);
        $this->assertSame(1, $event->participants()->where('user_id', $user->id)->count());

        app(HandleEventParticipationCallback::class)->handle($this->callbackPayload($event->id, 'leave'));
        app(HandleEventParticipationCallback::class)->handle($this->callbackPayload($event->id, 'leave'));

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventParticipantStatusEnum::LEFT->value,
        ]);

        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://api.telegram.org/bot123456:test-token/answerCallbackQuery');
    }

    public function test_unlinked_telegram_user_is_created_and_joined_from_chat_callback(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
        $event = Event::factory()->create();
        TelegramEventPublication::query()->create([
            'event_id' => $event->id,
            'chat_id' => '-1002136558099',
            'message_id' => 501,
            'status' => 'published',
        ]);

        app(HandleEventParticipationCallback::class)->handle($this->callbackPayload($event->id));

        $user = User::query()->where('username', 'tg_777')->firstOrFail();

        $this->assertSame(UserRegistrationChannelEnum::TELEGRAM_CHAT, $user->registration_channel);
        $this->assertDatabaseHas('telegram_accounts', [
            'telegram_user_id' => 777,
            'user_id' => $user->id,
            'username' => 'chat_player',
        ]);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => 'telegram',
            'value' => '777',
        ]);
        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventParticipantStatusEnum::CONFIRMED->value,
        ]);
        Http::assertSent(fn ($request): bool => $request['show_alert'] === false
            && str_contains($request['text'], 'Аккаунт MSKBA создан'));
    }

    public function test_unlinked_telegram_user_can_decline_and_response_is_saved(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
        $event = Event::factory()->create();
        TelegramEventPublication::query()->create([
            'event_id' => $event->id,
            'chat_id' => '-1002136558099',
            'message_id' => 501,
            'status' => 'published',
        ]);

        app(HandleEventParticipationCallback::class)->handle($this->callbackPayload($event->id, 'leave'));

        $user = User::query()->where('username', 'tg_777')->firstOrFail();

        $this->assertDatabaseHas('event_participants', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => EventParticipantStatusEnum::LEFT->value,
        ]);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Не пойду')
            && str_contains($request['text'], 'Аккаунт MSKBA создан'));
    }

    public function test_full_event_rejects_telegram_participation(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
        $event = Event::factory()->create(['max_participants' => 1]);
        $organizer = User::factory()->create();
        $participant = User::factory()->create();
        $event->participants()->create([
            'user_id' => $organizer->id,
            'role' => EventParticipantRoleEnum::ORGANIZER,
            'status' => EventParticipantStatusEnum::CONFIRMED,
        ]);
        TelegramAccount::query()->create([
            'user_id' => $participant->id,
            'telegram_user_id' => 777,
        ]);
        TelegramEventPublication::query()->create([
            'event_id' => $event->id,
            'chat_id' => '-1002136558099',
            'message_id' => 501,
            'status' => 'published',
        ]);

        app(HandleEventParticipationCallback::class)->handle($this->callbackPayload($event->id));

        $this->assertDatabaseMissing('event_participants', [
            'event_id' => $event->id,
            'user_id' => $participant->id,
        ]);
        Http::assertSent(fn ($request): bool => $request['show_alert'] === true
            && $request['text'] === 'Все места на мероприятии уже заняты.');
    }

    public function test_webhook_configuration_command_registers_callback_updates(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/setWebhook' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $this->artisan('telegram:configure-updates')
            ->expectsOutputToContain('Telegram webhook configured:')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request['url'] === route('integrations.telegram.webhook')
            && $request['secret_token'] === 'webhook-test-secret'
            && $request['allowed_updates'] === ['callback_query']);
    }

    public function test_polling_configuration_removes_webhook_without_dropping_updates(): void
    {
        config(['telegram.updates_transport' => 'polling']);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/deleteWebhook' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);

        $this->artisan('telegram:configure-updates')
            ->expectsOutputToContain('Telegram long polling configured')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://api.telegram.org/bot123456:test-token/deleteWebhook'
            && $request['drop_pending_updates'] === false);
    }

    public function test_long_polling_queues_telegram_callback(): void
    {
        config([
            'telegram.updates_transport' => 'polling',
            'telegram.polling_timeout' => 1,
        ]);
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [[
                    'update_id' => 9001,
                    'callback_query' => $this->callbackPayload(10),
                ]],
            ]),
        ]);

        $this->artisan('telegram:poll-updates', ['--once' => true])
            ->expectsOutput('Telegram updates processed: 1')
            ->assertSuccessful();

        Queue::assertPushed(
            ProcessTelegramCallbackJob::class,
            fn (ProcessTelegramCallbackJob $job): bool => $job->callback['id'] === 'callback-1',
        );
        $this->assertSame(9002, Cache::get('telegram:updates:offset'));
    }

    /** @return array<string, mixed> */
    private function callbackPayload(int $eventId, string $action = 'join'): array
    {
        return [
            'id' => 'callback-1',
            'from' => [
                'id' => 777,
                'username' => 'chat_player',
                'first_name' => 'Chat',
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
