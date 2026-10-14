<?php
namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardChangedBroadcast;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AccountDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER,
        UserStatusEnum $status = UserStatusEnum::UNCONFIRMED): User
    {
        $user = User::factory()->create(['system_role' => $role, 'status' => $status]);
        $user->createProfile([]);
        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'advertisement',
            'title' => 'Промо площадок',
            'body' => 'Открой нашу новую программу тренировок',
            'link_url' => 'https://example.org/trainings',
            'link_label' => 'Перейти',
            'image_url' => 'https://example.org/banner.jpg',
            'iframe_height' => 260, 'sort_order' => 5, 'size' => 'half',
            'enabled' => 1, 'can_hide' => 1, 'contact_filter' => 'any',
            'account_statuses' => ['confirmed'],
            'include_user_ids' => '', 'exclude_user_ids' => '',
        ], $overrides);
    }

    public function test_defaults_and_authorized_refresh_show_real_dashboard_widgets(): void
    {
        $user = $this->user();
        $this->getJson(route('account.dashboard.widgets'))->assertUnauthorized();

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('data-account-dashboard', false)
            ->assertSee('Статус пользователя')
            ->assertSee('Возможности MSKBA')
            ->assertSee('data-widget-can-hide="0"', false);

        $this->getJson(route('account.dashboard.widgets'))->assertOk()
            ->assertJsonStructure(['html'])->assertDontSee('html_code');
        $this->assertSame(3, AccountDashboardWidget::query()->count());
    }

    public function test_admin_targets_ad_by_status_and_specific_user_with_override_and_exclusion(): void
    {
        Event::fake([DashboardChangedBroadcast::class]);
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN, UserStatusEnum::CONFIRMED);
        $confirmed = $this->user(UserSystemRoleEnum::USER, UserStatusEnum::CONFIRMED);
        $unconfirmed = $this->user();
        $outsider = $this->user();

        $this->actingAs($outsider)->get(route('admin.account-dashboard.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.account-dashboard.index'))->assertOk()
            ->assertSee('Создать виджет')->assertSee('HTML / CSS / JS (песочница)');
        $this->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'include_user_ids' => (string) $unconfirmed->id,
            'exclude_user_ids' => (string) $outsider->id,
        ]))->assertRedirect();
        Event::assertDispatched(DashboardChangedBroadcast::class);

        $ad = AccountDashboardWidget::query()->where('kind', 'advertisement')->firstOrFail();
        $this->assertSame([$unconfirmed->id], $ad->include_user_ids);
        $this->actingAs($confirmed)->get(route('account'))->assertOk()->assertSee('Промо площадок');
        $this->actingAs($unconfirmed)->get(route('account'))->assertOk()->assertSee('Промо площадок');
        $this->actingAs($outsider)->get(route('account'))->assertOk()->assertDontSee('Промо площадок');

        $this->actingAs($admin)->put(route('admin.account-dashboard.widgets.update', $ad), $this->payload([
            'sort_order' => 1, 'enabled' => 0, 'include_user_ids' => (string) $unconfirmed->id,
        ]))->assertRedirect();
        $this->actingAs($confirmed)->getJson(route('account.dashboard.widgets'))
            ->assertOk()->assertDontSee('Промо площадок');
        $this->assertSame(1, (int) $ad->fresh()->sort_order);
    }

    public function test_html_js_css_widget_is_superadmin_only_and_isolated_in_sandboxed_iframe(): void
    {
        Event::fake([DashboardChangedBroadcast::class]);
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN, UserStatusEnum::CONFIRMED);
        $regular = $this->user();

        $this->actingAs($regular)->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'kind' => 'custom_html', 'link_url' => null,
        ]))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'kind' => 'custom_html', 'link_url' => null, 'account_statuses' => [],
            'title' => 'Промо HTML',
            'html_code' => '<div id="promo">Индивидуальная реклама</div>',
            'css_code' => '#promo{color:orange}',
            'js_code' => 'document.getElementById("promo").dataset.ready="yes";',
        ]))->assertRedirect();
        $html = $this->actingAs($regular)->get(route('account'))->assertOk()->getContent();
        $this->assertStringContainsString('sandbox="allow-scripts"', $html);
        $this->assertStringNotContainsString('allow-same-origin', $html);
        $this->assertStringContainsString('connect-src', $html);
        $this->assertStringContainsString('Индивидуальная реклама', $html);
        $this->assertStringNotContainsString('<div id="promo">', $html);
    }

    public function test_invalid_ad_url_and_untrusted_audience_fields_are_rejected(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN, UserStatusEnum::CONFIRMED);
        $this->actingAs($admin)->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'link_url' => 'javascript:alert(1)',
        ]))->assertSessionHasErrors(['link_url']);
        $this->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'include_user_ids' => '1, 2, invalid',
        ]))->assertSessionHasErrors(['include_user_ids']);
        $this->assertSame(0, AccountDashboardWidget::query()->where('kind', 'advertisement')->count());
    }
    public function test_status_widget_renders_html_per_state_without_touching_builtin_confirmation_action(): void
    {
        $widget = AccountDashboardWidget::query()->where('kind', 'account_status')->firstOrFail();
        $widget->update([
            'status_confirmed_html' => '<p><strong>Привет!</strong></p><a href="/account/profile" class="button secondary">Профиль</a>',
            'status_unconfirmed_html' => '<p>Пожалуйста, <em>подтвердите аккаунт</em>.</p>',
            'status_blocked_html' => '<p>Доступ ограничен. <a href="/faq">Помощь</a></p>',
        ]);

        $confirmed = $this->user(UserSystemRoleEnum::USER, UserStatusEnum::CONFIRMED);
        $this->actingAs($confirmed)->get(route('account'))->assertOk()
            ->assertSee('<strong>Привет!</strong>', false)
            ->assertSee('class="button secondary"', false)
            ->assertDontSee('Пожалуйста,');
        $unconfirmed = $this->user();
        $this->actingAs($unconfirmed)->get(route('account'))->assertOk()
            ->assertSee('<em>подтвердите аккаунт</em>', false)
            ->assertSee('Подтвердить аккаунт')
            ->assertDontSee('Доступ ограничен.');
        $blocked = $this->user();
        $blocked->update(['status' => UserStatusEnum::BLOCKED]);
        $this->actingAs($blocked)->get(route('account'))->assertForbidden();
        $fragment = view('theme::pages.account.partials.dashboard-widget-list', [
            'dashboardWidgets' => collect([$widget->fresh()]),
            'dashboardUser' => $blocked,
            'dashboardUnread' => 0,
        ])->render();
        $this->assertStringContainsString('Доступ ограничен.', $fragment);
        $this->assertStringNotContainsString('<em>подтвердите аккаунт</em>', $fragment);
    }

    public function test_functional_widget_html_and_dynamic_unread_placeholder_render_in_refresh(): void
    {
        $messages = AccountDashboardWidget::query()->where('kind', 'message_summary')->firstOrFail();
        $messages->update(['body' => '<p>Непрочитано: <strong>{{ unread_count }}</strong></p>'.
            '<a href="/account/notifications" class="button primary">К сообщениям</a>']);
        $start = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $start->update(['body' => '<ul><li>Выбери роли</li><li>Участвуй в играх</li></ul>']);

        $user = $this->user(UserSystemRoleEnum::USER, UserStatusEnum::CONFIRMED);
        $html = $this->actingAs($user)->getJson(route('account.dashboard.widgets'))
            ->assertOk()->json('html');

        $this->assertStringContainsString('Непрочитано: <strong>0</strong>', $html);
        $this->assertStringContainsString('href="/account/notifications"', $html);
        $this->assertStringContainsString('<li>Выбери роли</li>', $html);
        $this->assertStringNotContainsString('{{ unread_count }}', $html);
    }

    public function test_unchanged_native_widget_content_uses_previous_defaults(): void
    {
        $confirmed = $this->user(UserSystemRoleEnum::USER, UserStatusEnum::CONFIRMED);
        $this->actingAs($confirmed)->get(route('account'))->assertOk()
            ->assertSee('Аккаунт подтверждён. Теперь доступны возможности портала');
        $unconfirmed = $this->user();
        $this->actingAs($unconfirmed)->get(route('account'))->assertOk()
            ->assertSee('Подтверди аккаунт, чтобы получить доступ');
    }


    public function test_custom_corner_glow_is_scoped_to_one_widget_and_default_is_unchanged(): void
    {
        $user = $this->user(UserSystemRoleEnum::USER, UserStatusEnum::CONFIRMED);
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $widget->update(['gradient_color' => '#17B9ED']);
        $html = $this->actingAs($user)->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');

        $this->assertStringContainsString('--surface-glow: #17B9ED1e', $html);
        $this->assertSame(1, substr_count($html, '--surface-glow:'));
        $this->assertStringContainsString('data-dashboard-widget="1"', $html);
        $this->assertSame('#17B9ED', $widget->fresh()->gradient_color);
    }

    public function test_invalid_custom_glow_is_rejected_through_legacy_admin_endpoint(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN, UserStatusEnum::CONFIRMED);
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $payload = $this->payload([
            'kind' => 'getting_started',
            'gradient_color' => 'red;--surface:transparent',
            'link_url' => null,
        ]);
        $this->actingAs($admin)->put(route('admin.account-dashboard.widgets.update', $widget), $payload)
            ->assertSessionHasErrors(['gradient_color']);
        $this->assertNull($widget->fresh()->gradient_color);
    }


    public function test_legacy_advertising_editor_accepts_root_relative_url_and_dashboard_renders_same_tab_link(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN, UserStatusEnum::CONFIRMED);
        $this->actingAs($admin)->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'title' => 'Партнёры',
            'link_url' => '/partners?source=dashboard',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $widget = AccountDashboardWidget::query()->where('title', 'Партнёры')->firstOrFail();
        $this->assertSame('/partners?source=dashboard', $widget->link_url);
        $html = $this->getJson(route('account.dashboard.widgets'))->assertOk()->json('html');
        $this->assertStringContainsString('href="/partners?source=dashboard"', $html);
        $this->assertStringNotContainsString('href="/partners?source=dashboard" target="_blank"', $html);
    }

    public function test_legacy_advertising_editor_rejects_protocol_relative_url(): void
    {
        $admin = $this->user(UserSystemRoleEnum::SUPERADMIN, UserStatusEnum::CONFIRMED);
        $this->actingAs($admin)->post(route('admin.account-dashboard.widgets.store'), $this->payload([
            'link_url' => '//evil.example/partners',
        ]))->assertSessionHasErrors(['link_url']);
        $this->assertSame(0, AccountDashboardWidget::query()->where('kind', 'advertisement')->count());
    }

}
