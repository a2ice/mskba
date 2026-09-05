<?php

namespace Tests\Feature\Telegram;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLoginCallback;
use App\Modules\Telegram\Application\UseCases\HandleTelegramBotLoginStartMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramBotLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.bot_login_ttl' => 300,
        ]);

        Http::fake([
            'https://api.telegram.org/bot123456:test-token/*' => Http::response([
                'ok' => true,
                'result' => true,
            ]),
        ]);
    }

    public function test_guest_can_confirm_bot_login_and_browser_session_is_authenticated(): void
    {
        $start = $this
            ->postJson(route('auth.telegram.bot.start'), [
                'redirect_to' => '/account',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('bot_url', fn (string $url): bool => str_starts_with(
                $url,
                'https://t.me/MSKBABot?start=login_',
            ));
        $token = (string) $start->json('token');

        app(HandleTelegramBotLoginStartMessage::class)->handle($this->startMessage($token));

        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://api.telegram.org/bot123456:test-token/sendMessage'
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data')
                === "auth:login:{$token}");

        app(HandleTelegramBotLoginCallback::class)->handle($this->loginCallback($token));

        $this
            ->postJson(route('auth.telegram.bot.status'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', true)
            ->assertJsonPath('redirect_url', url('/account'));

        $user = User::query()->where('username', 'tg_777')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::TELEGRAM_WEB, $user->registration_channel);
        $this->assertNotNull($user->first_logged_in_at);
        $this->assertDatabaseHas('telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'bot_player',
        ]);
    }

    public function test_bot_login_acknowledges_callback_before_resolving_user(): void
    {
        $token = (string) $this
            ->postJson(route('auth.telegram.bot.start'))
            ->assertOk()
            ->json('token');
        $acknowledgedBeforeUserResolution = false;

        Http::fake(function ($request) use (&$acknowledgedBeforeUserResolution) {
            if (str_ends_with($request->url(), '/answerCallbackQuery')) {
                $acknowledgedBeforeUserResolution = User::query()->count() === 0;
            }

            return Http::response([
                'ok' => true,
                'result' => true,
            ]);
        });

        app(HandleTelegramBotLoginCallback::class)->handle($this->loginCallback($token));

        $this->assertTrue($acknowledgedBeforeUserResolution);
        $this->assertSame(1, User::query()->count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && data_get($request->data(), 'callback_query_id') === 'login-callback-1'
            && data_get($request->data(), 'text') === 'Подтверждаем вход…');
    }

    public function test_browser_waits_until_bot_login_is_confirmed(): void
    {
        $token = (string) $this
            ->postJson(route('auth.telegram.bot.start'))
            ->assertOk()
            ->json('token');

        $this
            ->postJson(route('auth.telegram.bot.status'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_status_polling_does_not_throttle_a_new_bot_login_start(): void
    {
        $token = (string) $this
            ->postJson(route('auth.telegram.bot.start'))
            ->assertOk()
            ->json('token');

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this
                ->postJson(route('auth.telegram.bot.status'), ['token' => $token])
                ->assertOk()
                ->assertJsonPath('status', 'pending');
        }

        $this
            ->postJson(route('auth.telegram.bot.start'))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
    }

    public function test_bare_start_command_gets_a_helpful_reply_instead_of_silence(): void
    {
        app(HandleTelegramBotLoginStartMessage::class)->handle([
            'message_id' => 99,
            'from' => [
                'id' => 777,
            ],
            'chat' => [
                'id' => 777,
                'type' => 'private',
            ],
            'text' => '/start',
        ]);

        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://api.telegram.org/bot123456:test-token/sendMessage'
            && str_contains(
                (string) data_get($request->data(), 'text'),
                'вернитесь на сайт',
            ));
    }

    public function test_bot_login_reuses_existing_telegram_account(): void
    {
        $user = User::factory()->create(['username' => 'existing_player']);
        $user->telegramAccount()->create([
            'telegram_user_id' => 777,
            'username' => 'old_name',
        ]);
        $token = (string) $this
            ->postJson(route('auth.telegram.bot.start'))
            ->assertOk()
            ->json('token');

        app(HandleTelegramBotLoginCallback::class)->handle($this->loginCallback($token));

        $this
            ->postJson(route('auth.telegram.bot.status'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('bot_player', $user->telegramAccount()->sole()->username);
    }

    public function test_bot_login_challenge_cannot_be_consumed_by_another_browser(): void
    {
        $user = User::factory()->create();
        $telegramAccount = $user->telegramAccount()->create([
            'telegram_user_id' => 777,
        ]);
        $challenges = app(TelegramBotLoginChallengeStore::class);
        $challenge = $challenges->create('first-browser', url('/account'));

        $this->assertTrue($challenges->approve(
            $challenge['token'],
            $user->id,
            $telegramAccount->id,
            false,
        ));
        $this->assertSame(
            ['status' => 'expired'],
            $challenges->consumeForBrowser(
                $challenge['token'],
                'second-browser',
                fn (): bool => true,
            ),
        );
        $this->assertNotNull($challenges->find($challenge['token']));
    }

    /** @return array<string, mixed> */
    private function startMessage(string $token): array
    {
        return [
            'message_id' => 100,
            'from' => [
                'id' => 777,
                'username' => 'bot_player',
                'first_name' => 'Bot',
                'last_name' => 'Player',
                'language_code' => 'ru',
            ],
            'chat' => [
                'id' => 777,
                'type' => 'private',
            ],
            'text' => "/start login_{$token}",
        ];
    }

    /** @return array<string, mixed> */
    private function loginCallback(string $token): array
    {
        return [
            'id' => 'login-callback-1',
            'from' => [
                'id' => 777,
                'username' => 'bot_player',
                'first_name' => 'Bot',
                'last_name' => 'Player',
                'language_code' => 'ru',
            ],
            'message' => [
                'message_id' => 101,
                'chat' => [
                    'id' => 777,
                    'type' => 'private',
                ],
            ],
            'data' => "auth:login:{$token}",
        ];
    }
}
