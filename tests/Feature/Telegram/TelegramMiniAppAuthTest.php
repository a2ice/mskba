<?php

namespace Tests\Feature\Telegram;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Venue\Domain\Models\Venue;
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

        $contact = $user->contacts()->sole();

        $this->assertSame(ContactTypeEnum::TELEGRAM, $contact->type);
        $this->assertSame('777', $contact->value);
        $this->assertSame('@mskba_user', $contact->displayValue());
        $this->assertTrue($contact->is_primary);
        $this->assertTrue($contact->hasBeenVerified());
        $this->assertSame(777, $contact->meta['telegram_user_id']);
        $this->assertTrue($user->hasVerifiedPrimaryContact());

        $this
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Основной контакт:')
            ->assertSee('Telegram: @mskba_user')
            ->assertSee('Основной контакт подтвержден')
            ->assertSee('Подтвердить аккаунт')
            ->assertDontSee('Добавьте и подтвердите контакт');

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
            ->assertSee('Играть')
            ->assertSee('Новая игра')
            ->assertSee('Найти площадку')
            ->assertSee('Добавить')
            ->assertSee('Открыть бота')
            ->assertDontSee('На главную')
            ->assertSee('Функционал находится в разработке')
            ->assertSee('data-telegram-dashboard', false)
            ->assertSee('data-telegram-feature-modal', false)
            ->assertSee('data-telegram-venue-create-form', false)
            ->assertSee('data-telegram-venue-edit-form', false)
            ->assertSee('data-telegram-venue-moderation-form', false)
            ->assertSee('Проверьте данные и отправьте на модерацию')
            ->assertSee('telegram-app-shell')
            ->assertDontSee('site-header')
            ->assertDontSee('site-footer');
    }

    public function test_telegram_venue_flow_requires_suggested_address_and_returns_json_for_every_step(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);
        $this->actingAs($user);

        $venueData = [
            'telegram_flow' => '1',
            'name' => 'Площадка Telegram',
            'type' => 'street_court',
            'location' => [
                'raw_address' => 'Россия, Москва, Тверская улица, 1',
                'city' => 'Москва',
                'street' => 'Тверская улица',
                'building' => '1',
                'latitude' => 55.757,
                'longitude' => 37.615,
            ],
        ];

        $this
            ->postJson(route('venues.store'), $venueData)
            ->assertStatus(422)
            ->assertJsonValidationErrors('location.address_selected');

        $this
            ->postJson(route('venues.store'), array_replace_recursive($venueData, [
                'location' => ['address_selected' => '1'],
            ]))
            ->assertCreated()
            ->assertJsonPath('message', 'Площадка создана. Проверьте данные и отправьте её на модерацию.')
            ->assertJsonStructure(['venue' => ['alias', 'update_url', 'moderation_url']]);

        $venue = Venue::query()->where('name', 'Площадка Telegram')->firstOrFail();

        $this
            ->putJson(route('venues.update', $venue->alias), array_replace_recursive($venueData, [
                'short_description' => 'Площадка рядом с центром',
                'location' => ['address_selected' => '1'],
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'Площадка сохранена.')
            ->assertJsonPath('venue.short_description', 'Площадка рядом с центром');

        $this
            ->postJson(route('venues.moderation.submit', $venue->alias))
            ->assertOk()
            ->assertJsonPath('message', 'Площадка отправлена на модерацию.');
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

        $contact = $user->contacts()->sole();

        $this->assertSame('777', $contact->value);
        $this->assertSame('@new_username', $contact->displayValue());
        $this->assertTrue($contact->is_primary);
        $this->assertTrue($contact->hasBeenVerified());
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_telegram_contact_does_not_replace_existing_primary_contact(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);

        $user = User::factory()->create();
        $email = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'player@example.com',
            'is_primary' => true,
            'verified_at' => now(),
        ]);
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
        ]);

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 777,
                    'username' => 'mskba_user',
                ]),
            ])
            ->assertOk();

        $telegramContact = $user->contacts()
            ->where('type', ContactTypeEnum::TELEGRAM->value)
            ->sole();

        $this->assertTrue($email->fresh()->is_primary);
        $this->assertFalse($telegramContact->is_primary);
        $this->assertTrue($telegramContact->hasBeenVerified());
        $this->assertSame(2, $user->contacts()->count());
    }

    public function test_telegram_auth_restores_deleted_contact_as_verified(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);

        $user = User::factory()->create();
        $deletedContact = $user->contacts()->create([
            'type' => ContactTypeEnum::TELEGRAM,
            'value' => '777',
            'is_primary' => false,
            'is_public' => true,
        ]);
        $deletedContact->delete();
        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 777,
        ]);

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 777,
                    'username' => 'restored_user',
                ]),
            ])
            ->assertOk();

        $restoredContact = $user->contacts()->sole();

        $this->assertSame($deletedContact->id, $restoredContact->id);
        $this->assertTrue($restoredContact->is_primary);
        $this->assertFalse($restoredContact->is_public);
        $this->assertTrue($restoredContact->hasBeenVerified());
        $this->assertSame('@restored_user', $restoredContact->displayValue());
        $this->assertSame(1, Contact::withTrashed()->count());
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
        $this->assertSame(0, Contact::query()->count());
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
