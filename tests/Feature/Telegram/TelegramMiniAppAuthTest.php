<?php

namespace Tests\Feature\Telegram;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Notification\Domain\Enums\UserNotificationSourceEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramMiniAppAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

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
                    'photo_url' => 'https://cdn.telegram.test/avatar.jpg',
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
            ->assertSee('aria-label="Dmitry Losev"', false)
            ->assertDontSee('Добавьте и подтвердите контакт');

        $this->assertDatabaseHas('telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 777,
            'username' => 'mskba_user',
            'first_name' => 'Dmitry',
            'last_name' => 'Losev',
        ]);

        Queue::assertPushed(SyncTelegramProfileAvatarJob::class);
    }

    public function test_telegram_mini_app_auth_resolves_event_start_destination(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);
        $event = Event::factory()->create();

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 778,
                    'username' => 'event_guest',
                ], "event_{$event->id}"),
            ])
            ->assertOk()
            ->assertJsonPath('telegram_user.start_param', "event_{$event->id}")
            ->assertJsonPath(
                'start_destination',
                route('events.show', $event->routeIdentifier(), false),
            );
    }

    public function test_telegram_mini_app_auth_ignores_unknown_start_destination(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);

        $this
            ->postJson(route('integrations.telegram.auth'), [
                'init_data' => $this->signedInitData([
                    'id' => 779,
                    'username' => 'unsafe_guest',
                ], 'https_example_com'),
            ])
            ->assertOk()
            ->assertJsonPath('start_destination', null);
    }

    public function test_legacy_telegram_integration_page_remains_available(): void
    {
        $this
            ->get(route('integrations.main'))
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
            ->assertSee('data-address-clear', false)
            ->assertSee('Я на площадке')
            ->assertSee(route('integrations.address-reverse'), false)
            ->assertSee('data-telegram-venue-search-form', false)
            ->assertSee('Название, адрес или описание')
            ->assertSee('Все статусы')
            ->assertSee('Любое метро')
            ->assertSee('Проверьте данные и отправьте на модерацию')
            ->assertSee('telegram-app-shell')
            ->assertDontSee('site-header')
            ->assertDontSee('site-footer');
    }

    public function test_telegram_entry_uses_shared_mobile_home_with_auth_bootstrap(): void
    {
        $this
            ->get(route('integrations.telegram.main'))
            ->assertOk()
            ->assertSessionHas('telegram_mini_app_context', true)
            ->assertSee('Играй в баскетбол')
            ->assertSee('site-header', false)
            ->assertSee('site-footer', false)
            ->assertSee('telegram-mini-app', false)
            ->assertSee('data-telegram-mini-app', false)
            ->assertSee('data-telegram-auth-bootstrap', false)
            ->assertSee('data-telegram-bootstrap-screen', false)
            ->assertSee('data-telegram-bootstrap-status', false)
            ->assertSee('Проверяем данные Telegram')
            ->assertSee('data-telegram-auth-url="'.route('integrations.telegram.auth').'"', false)
            ->assertSee('data-account-url="'.route('account').'"', false)
            ->assertSee('data-mobile-profile', false)
            ->assertSee('https://telegram.org/js/telegram-web-app.js', false)
            ->assertDontSee('telegram-app-shell');
    }

    public function test_telegram_presentation_context_persists_on_internal_pages_without_restarting_auth(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('integrations.telegram.main'))
            ->assertOk();

        $this
            ->get(route('account'))
            ->assertOk()
            ->assertSee('telegram-mini-app', false)
            ->assertSee('data-telegram-mini-app', false)
            ->assertSee('https://telegram.org/js/telegram-web-app.js', false)
            ->assertDontSee('data-telegram-auth-bootstrap', false)
            ->assertDontSee('data-telegram-bootstrap-screen', false)
            ->assertDontSee('data-telegram-auth-url=', false);
    }

    public function test_regular_mobile_browser_page_does_not_enable_telegram_presentation_context(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('account'))
            ->assertOk()
            ->assertDontSee('telegram-mini-app', false)
            ->assertDontSee('data-telegram-mini-app', false)
            ->assertDontSee('https://telegram.org/js/telegram-web-app.js', false);
    }

    public function test_shared_home_exposes_four_primary_actions_and_mobile_stats_bar(): void
    {
        $this
            ->get(route('welcome'))
            ->assertOk()
            ->assertSee('Играть')
            ->assertSee('Площадки')
            ->assertSee('Тренировки')
            ->assertSee('Команды')
            ->assertSee('Меню')
            ->assertSee('data-mobile-primary-bar', false)
            ->assertSee('data-mobile-nav-switcher', false)
            ->assertSee('Главное меню')
            ->assertSee('mobile-primary-bar__menu', false)
            ->assertSee('data-params="nav-shown;body"', false)
            ->assertSee('data-nav-toggle', false)
            ->assertSee('Закрыть')
            ->assertSee('Личный кабинет')
            ->assertSee('site-nav__mobile-account-link', false)
            ->assertSee('Новая игра')
            ->assertSee('онлайн')
            ->assertSee('data-online-users-count', false)
            ->assertDontSee('Найти игру')
            ->assertSee('Добавить площадку');
    }

    public function test_mobile_header_uses_authenticated_user_initials_without_avatar(): void
    {
        $user = User::factory()->create();
        $user->createProfile([
            'first_name' => 'Дмитрий',
            'last_name' => 'Лосев',
        ]);

        $this
            ->actingAs($user)
            ->get(route('welcome'))
            ->assertOk()
            ->assertSee('data-authenticated="1"', false)
            ->assertSee('data-profile-initials', false)
            ->assertSee('ДМ');
    }

    public function test_telegram_venue_search_filters_visible_venues_by_query_type_and_metro(): void
    {
        $user = User::factory()->create();
        $user->createProfile([]);
        $this->actingAs($user);

        $metro = MetroStation::factory()->create(['name' => 'Арбатская']);
        $location = Location::factory()->create();
        $location->metroStations()->attach($metro->id);

        Venue::factory()->create([
            'location_id' => $location->id,
            'name' => 'Центральная площадка',
            'alias' => 'tsentralnaya-ploshchadka',
            'type' => VenueTypeEnum::STREET_COURT,
            'status' => VenueStatusEnum::CONFIRMED,
            'raw_address' => 'Москва, Арбат, 10',
        ])->tags()->create([
            'name' => 'Круглосуточно',
            'slug' => 'kruglosutochno',
        ]);

        Venue::factory()->create([
            'location_id' => $location->id,
            'name' => 'Платная центральная площадка',
            'alias' => 'platnaya-tsentralnaya-ploshchadka',
            'type' => VenueTypeEnum::STREET_COURT,
            'status' => VenueStatusEnum::CONFIRMED,
            'requires_payment' => true,
            'requires_booking_approval' => false,
        ])->tags()->create([
            'name' => 'Круглосуточно',
            'slug' => 'kruglosutochno',
        ]);

        Venue::factory()->create([
            'name' => 'Чужая скрытая Арбатская площадка',
            'type' => VenueTypeEnum::STREET_COURT,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $this
            ->getJson(route('venues.search', [
                'query' => 'КРУГЛОСУТОЧНО',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'status' => VenueStatusEnum::CONFIRMED->value,
                'metro_station_id' => $metro->id,
                'requires_payment' => '0',
                'requires_booking_approval' => '0',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.name', 'Центральная площадка')
            ->assertJsonPath('venues.0.address', 'Москва, Арбат, 10')
            ->assertJsonPath('venues.0.status', VenueStatusEnum::CONFIRMED->label())
            ->assertJsonPath('venues.0.is_confirmed', true)
            ->assertJsonPath('venues.0.requires_payment', null)
            ->assertJsonPath('venues.0.requires_booking_approval', false)
            ->assertJsonPath('venues.0.has_free_access', false)
            ->assertJsonMissing(['name' => 'Чужая скрытая Арбатская площадка']);

        $this
            ->getJson(route('venues.search', [
                'query' => 'Арбатская',
                'requires_payment' => '0',
                'requires_booking_approval' => '0',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'venues')
            ->assertJsonPath('venues.0.name', 'Центральная площадка');
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
            'requires_payment' => '1',
            'requires_booking_approval' => '1',
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
            ->assertJsonPath('venue.requires_payment', null)
            ->assertJsonPath('venue.requires_booking_approval', true)
            ->assertJsonPath('venue.has_free_access', false)
            ->assertJsonStructure(['venue' => ['alias', 'update_url', 'moderation_url']]);

        $venue = Venue::query()->where('name', 'Площадка Telegram')->firstOrFail();

        $this
            ->getJson(route('account.venues.moderation.state', $venue->alias))
            ->assertOk()
            ->assertJsonPath('moderation.can_submit', true)
            ->assertJsonCount(0, 'moderation.history');

        $this
            ->putJson(route('account.venues.update', $venue->alias), array_replace_recursive($venueData, [
                'short_description' => 'Площадка рядом с центром',
                'tags' => 'крытая, бесплатная',
                'requires_payment' => '1',
                'requires_booking_approval' => '0',
                'location' => ['address_selected' => '1'],
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'Площадка сохранена.')
            ->assertJsonPath('venue.short_description', 'Площадка рядом с центром')
            ->assertJsonPath('venue.requires_payment', null)
            ->assertJsonPath('venue.requires_booking_approval', false)
            ->assertJsonPath('venue.has_free_access', false);

        $this->assertDatabaseHas('venue_tags', [
            'venue_id' => $venue->id,
            'name' => 'бесплатная',
            'slug' => 'besplatnaya',
        ]);

        $this
            ->postJson(route('account.venues.moderation.submit', $venue->alias))
            ->assertOk()
            ->assertJsonPath('message', 'Площадка отправлена на модерацию.');

        $this
            ->getJson(route('account.venues.moderation.state', $venue->alias))
            ->assertOk()
            ->assertJsonPath('moderation.can_submit', false)
            ->assertJsonPath('moderation.state', 'pending')
            ->assertJsonCount(1, 'moderation.history');

        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $moderationRequest = ModerationRequest::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.venues.moderation.reject', $moderationRequest), [
                'message' => 'Добавьте больше информации.',
            ])
            ->assertRedirect(route('admin.venues'));

        $this->actingAs($user)
            ->getJson(route('account.venues.moderation.state', $venue->alias))
            ->assertOk()
            ->assertJsonPath('moderation.can_submit', true)
            ->assertJsonPath('moderation.state', 'rejected')
            ->assertJsonPath('moderation.history.0.messages.0.message', 'Добавьте больше информации.');

        $this->actingAs(User::factory()->create())
            ->getJson(route('account.venues.moderation.state', $venue->alias))
            ->assertForbidden();
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

    public function test_repeated_telegram_auth_does_not_repeat_contact_confirmation_notification(): void
    {
        config(['telegram.bot_token' => '123456:test-token']);
        $initData = $this->signedInitData([
            'id' => 779,
            'username' => 'returning_user',
            'first_name' => 'Returning',
        ]);

        $this->postJson(route('integrations.telegram.auth'), ['init_data' => $initData])
            ->assertOk()
            ->assertJsonPath('created', true);

        $contact = Contact::query()
            ->where('type', ContactTypeEnum::TELEGRAM->value)
            ->where('value', '779')
            ->sole();
        $verifiedAt = $contact->verified_at;

        $this->postJson(route('integrations.telegram.auth'), ['init_data' => $initData])
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(
            1,
            UserNotification::query()
                ->where('user_id', $contact->contactable_id)
                ->where('payload->source', UserNotificationSourceEnum::CONTACT_CONFIRMATION->value)
                ->count(),
        );
        $this->assertTrue($verifiedAt->equalTo($contact->fresh()->verified_at));
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
    private function signedInitData(array $telegramUser, string $startParam = 'mskba_chat'): string
    {
        $params = [
            'auth_date' => (string) now()->timestamp,
            'chat_type' => 'sender',
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'start_param' => $startParam,
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
