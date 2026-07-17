<?php

namespace Tests\Feature\Telegram;

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramMiniAppAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_mini_app_auth_creates_user_and_logs_him_in(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 777,
                    'username' => 'mskba_user',
                    'first_name' => 'Dmitry',
                    'last_name' => 'Losev',
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', true)
            ->assertJsonPath('telegram_user.username', 'mskba_user')
            ->assertJsonPath('telegram_user.start_param', 'mskba_chat')
            ->assertJsonPath('user.registration_channel', UserRegistrationChannelEnum::TELEGRAM_MINI_APP->value);

        $user = User::query()->where('username', 'tg_777')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRegistrationChannelEnum::TELEGRAM_MINI_APP, $user->registration_channel);
        $this->assertSame(UserStatusEnum::UNCONFIRMED, $user->status);

        $this->assertDatabaseHas('telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'mskba_user',
            'first_name' => 'Dmitry',
            'last_name' => 'Losev',
        ]);
    }

    public function test_telegram_alias_page_is_available(): void
    {
        $this
            ->get(route('integrations.telegram.main'))
            ->assertOk()
            ->assertSee('Навигация Mini App')
            ->assertSee('Аккаунт')
            ->assertSee('Площадки')
            ->assertSee('Мероприятия')
            ->assertSee('Игры')
            ->assertSee('Тренировки')
            ->assertSee('Еще')
            ->assertSee('FAQ')
            ->assertSee(route('faq.index'), false)
            ->assertSee('Создать игру')
            ->assertSee('Найти игру')
            ->assertSee('Найти площадку')
            ->assertSee('Добавить площадку')
            ->assertSee('Функционал находится в разработке')
            ->assertSee('data-telegram-dashboard', false)
            ->assertSee('data-telegram-feature-modal', false)
            ->assertSee('telegram-app-shell')
            ->assertDontSee('site-header')
            ->assertDontSee('site-footer');
    }

    public function test_telegram_mini_app_auth_reuses_existing_telegram_account(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);

        $user = User::factory()->create([
            'username' => 'tg_777',
            'registration_channel' => UserRegistrationChannelEnum::TELEGRAM_MINI_APP,
        ]);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'old_username',
        ]);

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 777,
                    'username' => 'new_username',
                    'first_name' => 'New',
                ]),
            ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('telegram_user.username', 'new_username');

        $this->assertSame(1, User::query()->count());
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'new_username',
            'first_name' => 'New',
        ]);
    }

    public function test_telegram_mini_app_auth_rejects_invalid_signature(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 777,
                    'username' => 'mskba_user',
                ]).'tampered=1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, TelegramAccount::query()->count());
    }

    /**
     * @param  array<string, mixed>  $telegramUser
     */
    private function signedInitData(array $telegramUser): string
    {
        $params = [
            'auth_date' => (string) now()->timestamp,
            'chat_type' => 'sender',
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'start_param' => 'mskba_chat',
            'user' => json_encode($telegramUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        ksort($params);

        $dataCheckString = collect($params)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode("\n");

        $secretKey = hash_hmac('sha256', (string) config('telegram.bot_token'), 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
