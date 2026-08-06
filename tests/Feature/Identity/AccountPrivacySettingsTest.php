<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountPrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_uses_safe_privacy_and_notification_defaults(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.settings'))
            ->assertOk()
            ->assertSee('Настройки приватности и уведомлений')
            ->assertSee('Видимость в поиске')
            ->assertSee('Показывать мои контакты')
            ->assertSee('Кто может писать мне сообщения')
            ->assertSee('Кто может добавлять меня в группы')
            ->assertSee('Уведомления в Telegram')
            ->assertSee('Все уведомления');

        $this->assertSame(
            UserPrivacyVisibilityEnum::EVERYONE,
            UserPrivacySettingTypeEnum::GROUP_INVITATIONS->defaultVisibility(),
        );
        $this->assertDatabaseCount('user_privacy_settings', 0);
        $this->assertDatabaseCount('user_notification_settings', 0);
    }

    public function test_user_can_update_all_privacy_and_notification_settings_atomically(): void
    {
        $user = User::factory()->create();
        $allowedUser = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $response = $this->actingAs($user)->put(route('account.settings.privacy.update'), [
            'privacy' => [
                UserPrivacySettingTypeEnum::DISCOVERABILITY->value => [
                    'visibility' => UserPrivacyVisibilityEnum::SELECTED_USERS->value,
                    'allowed_user_ids' => [$allowedUser->getKey()],
                ],
                UserPrivacySettingTypeEnum::CONTACTS->value => [
                    'visibility' => UserPrivacyVisibilityEnum::NOBODY->value,
                ],
                UserPrivacySettingTypeEnum::MESSAGES->value => [
                    'visibility' => UserPrivacyVisibilityEnum::EVERYONE->value,
                ],
                UserPrivacySettingTypeEnum::GROUP_INVITATIONS->value => [
                    'visibility' => UserPrivacyVisibilityEnum::SELECTED_USERS->value,
                    'allowed_user_ids' => [$allowedUser->getKey()],
                ],
            ],
            'messenger_notifications' => UserMessengerNotificationPreferenceEnum::REQUESTS_ONLY->value,
        ]);

        $response
            ->assertRedirect(route('account.settings'))
            ->assertSessionHas('status', 'Настройки приватности и уведомлений сохранены.');

        $this->assertDatabaseCount('user_privacy_settings', 4);
        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $user->getKey(),
            'type' => UserPrivacySettingTypeEnum::MESSAGES->value,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE->value,
        ]);
        $this->assertDatabaseHas('user_notification_settings', [
            'user_id' => $user->getKey(),
            'messenger_notifications' => UserMessengerNotificationPreferenceEnum::REQUESTS_ONLY->value,
        ]);
        $this->assertDatabaseCount('user_privacy_setting_allowed_users', 2);
    }

    public function test_selected_users_mode_requires_a_user_and_rejects_the_account_owner(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['privacy'][UserPrivacySettingTypeEnum::CONTACTS->value] = [
            'visibility' => UserPrivacyVisibilityEnum::SELECTED_USERS->value,
            'allowed_user_ids' => [],
        ];

        $this->actingAs($user)
            ->put(route('account.settings.privacy.update'), $payload)
            ->assertSessionHasErrors('privacy.contacts.allowed_user_ids');

        $payload['privacy'][UserPrivacySettingTypeEnum::CONTACTS->value]['allowed_user_ids'] = [$user->getKey()];

        $this->actingAs($user)
            ->put(route('account.settings.privacy.update'), $payload)
            ->assertSessionHasErrors('privacy.contacts.allowed_user_ids.0');

        $this->assertDatabaseCount('user_privacy_settings', 0);
    }

    public function test_privacy_access_honours_defaults_and_selected_users(): void
    {
        $subject = User::factory()->create();
        $allowedUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $service = app(UserPrivacyAccessService::class);

        $this->assertTrue($service->allows($subject, $otherUser, UserPrivacySettingTypeEnum::DISCOVERABILITY));
        $this->assertTrue($service->allows($subject, $otherUser, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $this->assertFalse($service->allows($subject, $otherUser, UserPrivacySettingTypeEnum::CONTACTS));

        $setting = UserPrivacySetting::query()->create([
            'user_id' => $subject->getKey(),
            'type' => UserPrivacySettingTypeEnum::CONTACTS,
            'visibility' => UserPrivacyVisibilityEnum::SELECTED_USERS,
        ]);
        $setting->allowedUsers()->attach($allowedUser);

        $this->assertTrue($service->allows($subject, $allowedUser, UserPrivacySettingTypeEnum::CONTACTS));
        $this->assertFalse($service->allows($subject, $otherUser, UserPrivacySettingTypeEnum::CONTACTS));
        $this->assertTrue($service->allows($subject, $subject, UserPrivacySettingTypeEnum::CONTACTS));
    }

    public function test_user_search_respects_general_visibility(): void
    {
        $viewer = User::factory()->create();
        $visibleUser = User::factory()->create([
            'username' => 'visible_player',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $hiddenUser = User::factory()->create([
            'username' => 'hidden_player',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        UserPrivacySetting::query()->create([
            'user_id' => $hiddenUser->getKey(),
            'type' => UserPrivacySettingTypeEnum::DISCOVERABILITY,
            'visibility' => UserPrivacyVisibilityEnum::NOBODY,
        ]);

        $this->actingAs($viewer)
            ->getJson(route('account.settings.privacy.users', ['query' => 'player']))
            ->assertOk()
            ->assertJsonFragment(['id' => $visibleUser->getKey()])
            ->assertJsonMissing(['id' => $hiddenUser->getKey()]);
    }

    private function validPayload(): array
    {
        return [
            'privacy' => collect(UserPrivacySettingTypeEnum::cases())
                ->mapWithKeys(fn (UserPrivacySettingTypeEnum $type): array => [
                    $type->value => ['visibility' => $type->defaultVisibility()->value],
                ])
                ->all(),
            'messenger_notifications' => UserMessengerNotificationPreferenceEnum::ALL->value,
        ];
    }
}
