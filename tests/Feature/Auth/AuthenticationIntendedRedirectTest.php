<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\Services\TelegramBotLoginChallengeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AuthenticationIntendedRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config([
            'vk.app_id' => '12345',
            'vk.redirect_uri' => 'https://mskba.test/auth/vk/callback',
            'vk.authorize_url' => 'https://id.vk.test/authorize',
            'vk.token_url' => 'https://id.vk.test/oauth2/auth',
            'vk.user_info_url' => 'https://id.vk.test/oauth2/user_info',
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.login_widget_max_age' => 600,
            'telegram.bot_login_ttl' => 300,
        ]);
    }

    public function test_guest_booking_target_is_preserved_and_described_on_auth_pages(): void
    {
        $target = $this->bookingTarget();

        $this->get($target)->assertRedirect(route('login'));
        $this->assertSame($target, session('url.intended'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-auth-redirect-notice', false)
            ->assertSee('После входа вернём вас к нужному действию')
            ->assertSee('Бронирование площадки')
            ->assertSee('href="'.route('auth.vk.start').'"', false)
            ->assertDontSee('redirect_to='.urlencode(route('login')), false);

        $this->assertSame($target, session('url.intended'));

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('data-auth-redirect-notice', false)
            ->assertSee('Бронирование площадки');

        $this->assertSame($target, session('url.intended'));
    }

    public function test_safe_login_redirect_query_is_remembered_but_auth_and_external_targets_are_ignored(): void
    {
        $target = $this->bookingTarget();

        $this->get(route('login', ['redirect_to' => $target]))
            ->assertOk()
            ->assertSee('Бронирование площадки');
        $this->assertSame($target, session('url.intended'));

        $this->get(route('login', ['redirect_to' => route('login')]))
            ->assertOk()
            ->assertSee('Бронирование площадки');
        $this->assertSame($target, session('url.intended'));

        $this->get(route('login', ['redirect_to' => 'https://example.com/phishing']))
            ->assertOk()
            ->assertSee('Бронирование площадки');
        $this->assertSame($target, session('url.intended'));
    }

    public function test_password_login_returns_to_intended_target_and_consumes_it(): void
    {
        $target = $this->bookingTarget();
        $user = User::factory()->create([
            'username' => 'returning_player',
            'password' => 'password',
            'registration_channel' => UserRegistrationChannelEnum::SEED,
            'system_role' => UserSystemRoleEnum::USER,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->withSession(['url.intended' => $target])
            ->post(route('auth.login'), [
                'login' => 'returning_player',
                'password' => 'password',
            ])
            ->assertRedirect($target);

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('url.intended'));
    }

    public function test_registration_returns_to_intended_target_and_consumes_it(): void
    {
        $target = $this->bookingTarget();

        $this->withSession(['url.intended' => $target])
            ->post(route('auth.register'), [
                'username' => 'new_booking_player',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'privacy_consent' => '1',
            ])
            ->assertRedirect($target);

        $this->assertAuthenticated();
        $this->assertNull(session('url.intended'));
    }

    public function test_vk_login_uses_intended_target_instead_of_login_page_and_consumes_it_on_success(): void
    {
        $target = $this->bookingTarget();

        $start = $this->withSession(['url.intended' => $target])
            ->get(route('auth.vk.start'));
        $start->assertRedirectContains('https://id.vk.test/authorize?');

        $location = (string) $start->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $state = (string) $query['state'];

        $this->assertSame($target, session('vk.oauth_flows')[$state]['redirect_url']);
        $this->assertSame($target, session('url.intended'));

        $this->fakeVk($state, '16601');

        $this->get(route('auth.vk.callback', [
            'state' => $state,
            'code' => 'authorization-code',
            'device_id' => 'device-1',
        ]))->assertRedirect($target);

        $this->assertAuthenticated();
        $this->assertNull(session('url.intended'));
    }

    public function test_cancelled_vk_login_keeps_intended_target_for_retry(): void
    {
        $target = $this->bookingTarget();
        $start = $this->withSession(['url.intended' => $target])
            ->get(route('auth.vk.start'));

        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = (string) $query['state'];

        $this->get(route('auth.vk.callback', [
            'state' => $state,
            'error' => 'access_denied',
        ]))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame($target, session('url.intended'));
    }

    public function test_telegram_web_login_returns_to_intended_target_and_consumes_it(): void
    {
        $target = $this->bookingTarget();

        $this->withSession(['url.intended' => $target])
            ->postJson(route('auth.telegram'), [
                'telegram_user' => $this->signedTelegramPayload([
                    'id' => 16602,
                    'username' => 'booking_player',
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('redirect_url', $target);

        $this->assertAuthenticated();
        $this->assertNull(session('url.intended'));
    }

    public function test_telegram_bot_login_keeps_target_during_challenge_and_consumes_it_on_success(): void
    {
        $target = $this->bookingTarget();
        $start = $this->withSession(['url.intended' => $target])
            ->postJson(route('auth.telegram.bot.start'))
            ->assertOk()
            ->assertJsonPath('status', 'pending');
        $token = (string) $start->json('token');

        $this->assertSame($target, session('url.intended'));
        $challenge = app(TelegramBotLoginChallengeStore::class)->find($token);
        $this->assertNotNull($challenge);
        $this->assertSame($target, $challenge['redirect_url']);

        $user = User::factory()->create();
        $telegramAccount = $user->telegramAccount()->create([
            'telegram_user_id' => 16603,
            'username' => 'bot_booking_player',
        ]);

        $this->assertTrue(app(TelegramBotLoginChallengeStore::class)->approve(
            $token,
            $user->id,
            $telegramAccount->id,
            false,
        ));

        $this->postJson(route('auth.telegram.bot.status'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('redirect_url', $target);

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('url.intended'));
    }

    private function bookingTarget(): string
    {
        return route('events.wizard', [
            'venue_id' => 11,
            'venue_court_id' => 14,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function signedTelegramPayload(array $overrides): array
    {
        $payload = array_replace([
            'id' => 16602,
            'first_name' => 'Player',
            'auth_date' => now()->timestamp,
        ], $overrides);

        ksort($payload);
        $dataCheckString = collect($payload)
            ->map(fn (mixed $value, string $key): string => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash('sha256', (string) config('telegram.bot_token'), true);
        $payload['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return $payload;
    }

    private function fakeVk(string $state, string $userId): void
    {
        Http::fake([
            'id.vk.test/oauth2/auth*' => Http::response([
                'access_token' => 'access-token',
                'user_id' => $userId,
                'state' => $state,
            ]),
            'id.vk.test/oauth2/user_info*' => Http::response([
                'user' => [
                    'user_id' => $userId,
                    'first_name' => 'Иван',
                    'last_name' => 'Петров',
                    'avatar' => 'https://example.test/avatar.jpg',
                ],
            ]),
        ]);
    }
}
