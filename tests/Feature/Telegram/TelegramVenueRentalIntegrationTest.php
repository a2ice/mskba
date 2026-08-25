<?php

namespace Tests\Feature\Telegram;

use App\Modules\Coordination\Domain\Enums\VenueRentalCoordinationStatus;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationCreated;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\Services\TelegramMiniAppStartDestinationResolver;
use App\Modules\Telegram\Application\Services\TelegramVenueRentalMessageBuilder;
use App\Modules\Telegram\Application\UseCases\HandleVenueRentalCoordinationCallback;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalPublication;
use App\Modules\Telegram\Domain\Models\TelegramVenueRentalUpdate;
use App\Modules\Telegram\Infrastructure\Jobs\ProcessTelegramCallbackJob;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramVenueRentalPublicationJob;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TelegramVenueRentalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.venue_rental.rental_flow' => true,
            'features.venue_rental.coordination' => true,
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBATestBot',
            'telegram.api_ip' => null,
            'telegram.http_proxy' => null,
            'telegram.webhook_secret' => 'rental-webhook-secret',
        ]);
    }

    public function test_created_public_coordination_prepares_chat_binding_and_status_card(): void
    {
        Queue::fake();
        $coordination = $this->coordination();
        $chat = $this->chat();

        event(new VenueRentalCoordinationCreated($coordination->id));

        $publication = TelegramVenueRentalPublication::query()->firstOrFail();
        $this->assertSame($coordination->id, $publication->coordination_id);
        $this->assertSame($chat->id, $publication->chat_id);
        Queue::assertPushed(SyncTelegramVenueRentalPublicationJob::class);

        $coordination->load(['venue.schedule', 'booking', 'participants']);
        $messages = app(TelegramVenueRentalMessageBuilder::class);
        $this->assertStringContainsString('Время ещё не забронировано', $messages->text($coordination));
        $this->assertSame(
            "rentalcoord:{$coordination->id}:join",
            $messages->replyMarkup($coordination)['inline_keyboard'][0][0]['callback_data'],
        );
        $this->assertStringContainsString('startapp=rental_coordination_', $messages->replyMarkup($coordination)['inline_keyboard'][1][0]['url']);
    }

    public function test_linked_confirmed_user_joins_once_when_callback_is_replayed(): void
    {
        Queue::fake();
        $coordination = $this->coordination();
        $publication = $this->publication($coordination);
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'raw_data' => [],
        ]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $callback = $this->callbackPayload($coordination, $publication, 'callback-rental-1');
        $handler = app(HandleVenueRentalCoordinationCallback::class);

        $handler->handle($callback, 9001);
        $handler->handle($callback, 9001);

        $this->assertSame(1, $coordination->participants()->where('user_id', $user->id)->count());
        $receipt = TelegramVenueRentalUpdate::query()->firstOrFail();
        $this->assertSame('completed', $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame(9001, $receipt->update_id);
        Http::assertSentCount(2);
    }

    public function test_closed_coordination_callback_returns_current_state_without_effect(): void
    {
        Queue::fake();
        $coordination = $this->coordination(['status' => VenueRentalCoordinationStatus::CLOSED, 'closed_at' => now()]);
        $publication = $this->publication($coordination);
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 777, 'raw_data' => []]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(HandleVenueRentalCoordinationCallback::class)->handle(
            $this->callbackPayload($coordination, $publication, 'callback-rental-closed'),
            9002,
        );

        $this->assertDatabaseMissing('venue_rental_coordination_participants', ['user_id' => $user->id]);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'Актуально: Сбор закрыт'));
    }

    public function test_unconfirmed_telegram_identity_cannot_join(): void
    {
        Queue::fake();
        $coordination = $this->coordination();
        $publication = $this->publication($coordination);
        $user = User::factory()->create(['status' => UserStatusEnum::UNCONFIRMED]);
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 777, 'raw_data' => []]);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(HandleVenueRentalCoordinationCallback::class)->handle(
            $this->callbackPayload($coordination, $publication, 'callback-rental-unconfirmed'),
            9003,
        );

        $this->assertDatabaseMissing('venue_rental_coordination_participants', ['user_id' => $user->id]);
        $this->assertSame('completed', TelegramVenueRentalUpdate::query()->firstOrFail()->status);
        Http::assertSent(fn ($request): bool => str_contains((string) $request['text'], 'подтверждённый аккаунт'));
    }

    public function test_missing_telegram_message_is_recreated_without_changing_domain_state(): void
    {
        $coordination = $this->coordination();
        $publication = $this->publication($coordination);
        Http::fake([
            'https://api.telegram.org/bot123456:test-token/editMessageText' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message to edit not found',
            ], 400),
            'https://api.telegram.org/bot123456:test-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7777],
            ]),
        ]);

        app()->call([new SyncTelegramVenueRentalPublicationJob($publication->id), 'handle']);

        $this->assertSame(7777, $publication->refresh()->message_id);
        $this->assertSame('published', $publication->status);
        $this->assertDatabaseCount('venue_bookings', 0);
    }

    public function test_mini_app_signature_and_private_deep_link_are_checked_server_side(): void
    {
        $coordination = $this->coordination(['visibility' => 'private']);
        [$organizerId, $strangerId] = [$coordination->organizer_user_id, User::factory()->create()->id];
        $resolver = app(TelegramMiniAppStartDestinationResolver::class);
        $start = 'rental_coordination_'.$coordination->public_id;

        $this->assertSame(route('venue-rental-coordinations.show', $coordination, false), $resolver->resolve($start, $organizerId));
        $this->assertNull($resolver->resolve($start, $strangerId));
        $this->postJson(route('integrations.telegram.auth'), ['init_data' => 'auth_date=1&hash=invalid'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');
    }

    public function test_webhook_passes_update_id_to_deduplicated_callback_job(): void
    {
        Queue::fake();
        $coordination = $this->coordination();
        $publication = $this->publication($coordination);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'rental-webhook-secret')
            ->postJson(route('integrations.telegram.webhook'), [
                'update_id' => 9010,
                'callback_query' => $this->callbackPayload($coordination, $publication, 'callback-rental-webhook'),
            ])->assertOk();

        Queue::assertPushed(
            ProcessTelegramCallbackJob::class,
            fn (ProcessTelegramCallbackJob $job): bool => $job->updateId === 9010
                && $job->callback['id'] === 'callback-rental-webhook',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function coordination(array $overrides = []): VenueRentalCoordination
    {
        $organizer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $actor = app(CurrentActorResolver::class)->resolve($organizer, null);
        $venue = Venue::factory()->create();
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $coordination = VenueRentalCoordination::query()->create(array_replace([
            'public_id' => (string) Str::uuid(),
            'organizer_actor_id' => $actor->id,
            'organizer_user_id' => $organizer->id,
            'venue_id' => $venue->id,
            'title' => 'Ищем игроков на вечер',
            'status' => VenueRentalCoordinationStatus::OPEN,
            'visibility' => 'public',
            'participants_visibility' => 'participants',
            'scope' => VenueBookingScopeEnum::WHOLE,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ], $overrides));
        $coordination->participants()->create([
            'user_id' => $organizer->id,
            'joined_at' => now(),
        ]);

        return $coordination;
    }

    private function chat(): TelegramChat
    {
        return TelegramChat::query()->create([
            'telegram_chat_id' => -1001234567890,
            'title' => 'MSKBA Test',
            'type' => 'supergroup',
            'is_active' => true,
            'publishes_coordination' => true,
        ]);
    }

    private function publication(VenueRentalCoordination $coordination): TelegramVenueRentalPublication
    {
        return TelegramVenueRentalPublication::query()->create([
            'coordination_id' => $coordination->id,
            'chat_id' => $this->chat()->id,
            'message_id' => 501,
            'status' => 'published',
        ]);
    }

    /** @return array<string, mixed> */
    private function callbackPayload(VenueRentalCoordination $coordination, TelegramVenueRentalPublication $publication, string $id): array
    {
        return [
            'id' => $id,
            'from' => [
                'id' => 777,
                'username' => 'rental_player',
                'first_name' => 'Rental',
                'last_name' => 'Player',
            ],
            'message' => [
                'message_id' => $publication->message_id,
                'chat' => ['id' => $publication->chat->telegram_chat_id],
            ],
            'data' => "rentalcoord:{$coordination->id}:join",
        ];
    }
}
