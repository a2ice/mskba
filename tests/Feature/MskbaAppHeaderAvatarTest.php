<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\Services\UserNotificationCounterStore;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppHeaderAvatarTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = config('themes.active');
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    protected function tearDown(): void
    {
        config()->set('themes.active', $this->previousTheme);
        View::replaceNamespace('theme', resource_path('themes/'.$this->previousTheme.'/views'));
        app()->forgetInstance(ThemeResolver::class);
        parent::tearDown();
    }

    private function createNotifications(User $user, int $new, int $read = 0): void
    {
        for ($i = 0; $i < $new + $read; $i++) {
            UserNotification::query()->create([
                'user_id' => $user->id,
                'type' => UserNotificationTypeEnum::SYSTEM,
                'status' => $i < $new ? UserNotificationStatusEnum::NEW : UserNotificationStatusEnum::READ,
                'title' => 'Тестовое уведомление '.$i,
                'body' => 'Проверка счётчика.',
            ]);
        }
    }

    public function test_staff_role_color_is_on_avatar_border_even_without_unread_notifications(): void
    {
        foreach ([
            UserSystemRoleEnum::SUPERADMIN,
            UserSystemRoleEnum::ADMIN,
            UserSystemRoleEnum::MODERATOR,
            UserSystemRoleEnum::EDITOR,
        ] as $role) {
            $user = User::factory()->create([
                'username' => 'test_'.$role->value,
                'system_role' => $role,
                'status' => UserStatusEnum::CONFIRMED,
            ]);

            $response = $this->actingAs($user)->get(route('welcome'))->assertOk();
            $roleColor = $role->avatarBorderColor();
            $response->assertSee('class="avatar app-user-avatar app-header-avatar"', false)
                ->assertSee('data-avatar-role="'.$role->value.'"', false)
                ->assertSee('title="'.$role->label().'"', false)
                ->assertSee('--app-avatar-role-color: '.$roleColor, false)
                ->assertSee('class="app-header-account-label">'.$user->username.'</span>', false)
                ->assertSee('aria-label="Личный кабинет, роль: '.$role->label().'"', false)
                ->assertDontSee('app-user-avatar__role-letter')
                ->assertSee('class="app-user-avatar__placeholder"', false)
                ->assertSee('<use href="#user"/>', false)
                ->assertSee('data-notification-count', false)
                ->assertSee('aria-label="Непрочитанных уведомлений: 0"', false);
            $this->assertSame(1, substr_count($response->getContent(), 'data-avatar-role='));
        }
    }

    public function test_regular_and_system_accounts_show_unread_count_without_staff_role_color(): void
    {
        foreach ([UserSystemRoleEnum::USER, UserSystemRoleEnum::SYSTEM] as $role) {
            $user = User::factory()->create([
                'username' => 'test_'.$role->value,
                'system_role' => $role,
                'status' => UserStatusEnum::CONFIRMED,
            ]);
            $this->createNotifications($user, 1, 1);

            $this->actingAs($user)->get(route('welcome'))->assertOk()
                ->assertSee('class="avatar app-user-avatar app-header-avatar"', false)
                ->assertSee('aria-label="Личный кабинет"', false)
                ->assertDontSee('data-avatar-role=', false)
                ->assertSee('aria-label="Непрочитанных уведомлений: 1"', false);
        }
    }

    public function test_header_and_both_account_sidebar_counters_share_total_and_hide_at_zero(): void
    {
        $user = User::factory()->create([
            'username' => 'my_login',
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $response = $this->actingAs($user)->get(route('account.roles'))->assertOk();
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(3, $xpath->query('//*[@data-notification-count]')->length);
        $this->assertSame(3, $xpath->query('//*[@data-notification-count][@hidden]')->length);
        $this->assertSame(1, $xpath->query('//header[@data-notification-user-id="'.$user->id.'"]')->length);
        $this->assertStringContainsString('data-notification-sync-url="'.route('account.notifications.new').'"', $html);

        $this->createNotifications($user, 2, 1);
        // Creating notifications via the application's use case invalidates
        // the canonical counter. Here we insert fixtures directly into SQL.
        app(UserNotificationCounterStore::class)->forget($user->id);
        $html = $this->get(route('account.roles'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(3, $xpath->query('//*[@data-notification-count]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-notification-count][@hidden]')->length);
        $this->assertSame(3, $xpath->query('//*[@data-notification-count][normalize-space(.)="2"]')->length);
        $this->assertStringContainsString('class="app-header-account-label">my_login</span>', $html);
    }

    public function test_avatar_uses_infinity_only_after_nine_unread_notifications(): void
    {
        $user = User::factory()->create([
            'username' => 'overflow_login',
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $this->createNotifications($user, 9);
        $response = $this->actingAs($user)->get(route('account.profile'))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[contains(@class, "app-user-avatar__unread")][@data-unread-count="9" and normalize-space(.)="9"]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-notification-count][not(contains(@class, "app-user-avatar__unread"))][normalize-space(.)="9"]')->length);

        $this->createNotifications($user, 1);
        app(UserNotificationCounterStore::class)->forget($user->id);
        $response = $this->get(route('account.profile'))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[contains(@class, "app-user-avatar__unread")][@data-unread-count="10" and normalize-space(.)="∞"]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-notification-count][not(contains(@class, "app-user-avatar__unread"))][normalize-space(.)="10"]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class, "app-user-avatar__unread")][@aria-label="Непрочитанных уведомлений: 10"]')->length);
    }

    public function test_guest_does_not_render_account_notification_tracking(): void
    {
        $this->get(route('welcome'))->assertOk()
            ->assertDontSee('class="avatar app-user-avatar app-header-avatar"', false)
            ->assertDontSee('data-avatar-role=', false)
            ->assertDontSee('data-notification-user-id=', false)
            ->assertDontSee('data-notification-count', false);
    }
}
