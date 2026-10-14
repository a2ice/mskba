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
}
