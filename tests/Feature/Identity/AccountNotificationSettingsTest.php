<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_notification_channels_without_changing_privacy_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('account.settings.notifications.update'), [
                'email_notifications' => UserMessengerNotificationPreferenceEnum::SYSTEM_AND_REQUESTS->value,
                'messenger_notifications' => UserMessengerNotificationPreferenceEnum::NONE->value,
            ])
            ->assertRedirect(route('account.settings'))
            ->assertSessionHasNoErrors();

        $setting = UserNotificationSetting::query()->where('user_id', $user->id)->sole();
        $this->assertSame(UserMessengerNotificationPreferenceEnum::SYSTEM_AND_REQUESTS, $setting->email_notifications);
        $this->assertSame(UserMessengerNotificationPreferenceEnum::NONE, $setting->messenger_notifications);
        $this->assertDatabaseCount('user_privacy_settings', 0);
    }

    public function test_notification_channel_settings_reject_invalid_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('account.settings.notifications.update'), [
                'email_notifications' => 'unknown',
                'messenger_notifications' => UserMessengerNotificationPreferenceEnum::ALL->value,
            ])
            ->assertSessionHasErrors('email_notifications');

        $this->assertDatabaseCount('user_notification_settings', 0);
    }
}
