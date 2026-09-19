<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LegacyPrivacyLockdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_active_users_are_closed_and_receive_one_relative_settings_notification(): void
    {
        $legacy = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'personal_data_distribution_required_at' => null,
            'personal_data_distribution_setup_completed_at' => null,
        ]);
        $allowedViewer = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => now(),
        ]);

        $profileSetting = UserPrivacySetting::query()->create([
            'user_id' => $legacy->id,
            'type' => UserPrivacySettingTypeEnum::PROFILE,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);
        $discoverabilitySetting = UserPrivacySetting::query()->create([
            'user_id' => $legacy->id,
            'type' => UserPrivacySettingTypeEnum::DISCOVERABILITY,
            'visibility' => UserPrivacyVisibilityEnum::SELECTED_USERS,
        ]);
        $discoverabilitySetting->allowedUsers()->attach($allowedViewer->id);

        $protected = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'personal_data_distribution_required_at' => now()->subMinute(),
            'personal_data_distribution_setup_completed_at' => now()->subMinute(),
        ]);
        UserPrivacySetting::query()->create([
            'user_id' => $protected->id,
            'type' => UserPrivacySettingTypeEnum::PROFILE,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);

        $migration = require database_path('migrations/2026_09_19_160000_lock_legacy_user_privacy_and_notify.php');
        $migration->up();

        $legacy->refresh();
        $this->assertNotNull($legacy->personal_data_distribution_required_at);
        $this->assertNull($legacy->personal_data_distribution_setup_completed_at);

        $this->assertSame(
            count(UserPrivacySettingTypeEnum::cases()),
            UserPrivacySetting::query()->where('user_id', $legacy->id)->count(),
        );
        $this->assertSame(
            0,
            UserPrivacySetting::query()
                ->where('user_id', $legacy->id)
                ->where('visibility', '!=', UserPrivacyVisibilityEnum::NOBODY->value)
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('user_privacy_setting_allowed_users')
                ->whereIn(
                    'privacy_setting_id',
                    UserPrivacySetting::query()->where('user_id', $legacy->id)->select('id'),
                )
                ->count(),
        );

        $notification = UserNotification::query()
            ->where('user_id', $legacy->id)
            ->where('title', 'Проверьте настройки приватности')
            ->sole();

        $this->assertSame(UserNotificationTypeEnum::PROFILE, $notification->type);
        $this->assertSame(UserNotificationStatusEnum::NEW, $notification->status);
        $this->assertSame('/account/settings', $notification->action_url);
        $this->assertSame('Настроить приватность', $notification->action_text);
        $this->assertSame('identity.privacy_review_required', $notification->payload['source'] ?? null);

        $this->assertDatabaseHas('user_privacy_settings', [
            'user_id' => $protected->id,
            'type' => UserPrivacySettingTypeEnum::PROFILE->value,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE->value,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $protected->id,
            'title' => 'Проверьте настройки приватности',
        ]);

        // A defensive rerun must not duplicate the notification.
        $migration->up();
        $this->assertSame(
            1,
            UserNotification::query()
                ->where('user_id', $legacy->id)
                ->where('title', 'Проверьте настройки приватности')
                ->count(),
        );
    }
}
