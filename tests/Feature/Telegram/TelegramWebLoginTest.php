<?php

namespace Tests\Feature\Telegram;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramWebLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.bot_token' => '123456:test-token',
            'telegram.bot_username' => 'MSKBABot',
            'telegram.login_widget_max_age' => 600,
        ]);
    }

    public function test_login_widget_creates_user_and_authenticated_session(): void
    {
        Queue::fake();

        $this
            ->postJson(route('auth.telegram'), [
                'telegram_user' => $this->signedPayload([
                    'id' => 777,
                    'username' => 'new_player',
                    'first_name' => 'Иван',
                    'last_name' => 'Петров',
                    'photo_url' => 'https://t.me/i/userpic/320/avatar.jpg',
                ]),
                'redirect_to' => '/account',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', true)
            ->assertJsonPath('redirect_url', url('/account'));

        $user = User::query()->where('username', 'tg_777')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::TELEGRAM_WEB, $user->registration_channel);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);
        $this->assertNotNull($user->first_logged_in_at);

        $this->assertDatabaseHas('telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'new_player',
        ]);
        $this->assertDatabaseHas('contacts', [
            'contactable_type' => 'user',
            'contactable_id' => $user->id,
            'type' => ContactTypeEnum::TELEGRAM->value,
            'value' => '777',
        ]);

        $contact = $user->contacts()->sole();
        $this->assertTrue($contact->hasBeenVerified());
        $this->assertSame('@new_player', $contact->displayValue());
        Queue::assertPushed(SyncTelegramProfileAvatarJob::class);
    }

    public function test_login_widget_reuses_existing_telegram_account(): void
    {
        $user = User::factory()->create(['username' => 'existing_player']);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'old_name',
        ]);

        $this
            ->postJson(route('auth.telegram'), [
                'telegram_user' => $this->signedPayload([
                    'id' => 777,
                    'username' => 'current_name',
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('redirect_url', route('account'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('current_name', $user->telegramAccount()->sole()->username);
        $this->assertSame('@current_name', $user->contacts()->sole()->displayValue());
    }

    public function test_login_widget_rejects_invalid_or_expired_signature(): void
    {
        $invalid = $this->signedPayload(['id' => 777, 'username' => 'player']);
        $invalid['username'] = 'tampered';

        $this
            ->postJson(route('auth.telegram'), ['telegram_user' => $invalid])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');

        $expired = $this->signedPayload([
            'id' => 778,
            'auth_date' => now()->subHour()->timestamp,
        ]);

        $this
            ->postJson(route('auth.telegram'), ['telegram_user' => $expired])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_login_widget_does_not_authenticate_blocked_user(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::BLOCKED]);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
        ]);

        $this
            ->postJson(route('auth.telegram'), [
                'telegram_user' => $this->signedPayload(['id' => 777]),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Аккаунт заблокирован. Обратитесь в поддержку.');

        $this->assertGuest();
    }

    public function test_login_widget_ignores_external_redirect_and_is_rendered_for_guests(): void
    {
        $this
            ->get(route('welcome'))
            ->assertOk()
            ->assertSee('data-telegram-bot-login', false)
            ->assertSee(route('auth.telegram.bot.start', [], false), false)
            ->assertSee(route('auth.telegram.bot.status', [], false), false)
            ->assertSee('data-telegram-login="MSKBABot"', false)
            ->assertSee('auth-telegram-login__widget" hidden', false)
            ->assertSee(route('auth.telegram', [], false), false);

        $this
            ->postJson(route('auth.telegram'), [
                'telegram_user' => $this->signedPayload(['id' => 777]),
                'redirect_to' => 'https://example.com/phishing',
            ])
            ->assertOk()
            ->assertJsonPath('redirect_url', route('account'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signedPayload(array $overrides): array
    {
        $payload = array_replace([
            'id' => 777,
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
}
