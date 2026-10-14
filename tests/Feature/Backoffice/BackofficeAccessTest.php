<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackofficeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(UserSystemRoleEnum $role, UserStatusEnum $status = UserStatusEnum::CONFIRMED): User
    {
        $user = User::factory()->create(['system_role' => $role, 'status' => $status]);
        $user->createProfile([]);

        return $user;
    }

    public function test_panel_is_restricted_to_confirmed_admins_and_reuses_web_session(): void
    {
        $user = $this->account(UserSystemRoleEnum::USER);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $unconfirmed = $this->account(UserSystemRoleEnum::ADMIN, UserStatusEnum::UNCONFIRMED);

        $this->assertFalse($user->canAccessPanel(app(Panel::class)->id('backoffice')));
        $this->assertTrue($admin->canAccessPanel(app(Panel::class)->id('backoffice')));
        $this->assertFalse($unconfirmed->canAccessPanel(app(Panel::class)->id('backoffice')));

        $this->get('/backoffice')->assertRedirect(route('login'));
        $this->actingAs($user)->get('/backoffice')->assertForbidden();
        $this->actingAs($admin)->get('/backoffice')->assertOk();
    }

    public function test_resource_page_uses_existing_widget_records_and_limits_custom_html_to_superadmin(): void
    {
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $widget = AccountDashboardWidget::query()->create([
            'kind' => 'information', 'title' => 'Анонс соревнований', 'sort_order' => 50,
            'size' => 'half', 'enabled' => true, 'can_hide' => true, 'contact_filter' => 'any',
        ]);
        $custom = AccountDashboardWidget::query()->create([
            'kind' => 'custom_html', 'title' => 'Код суперадминистратора',
            'sort_order' => 60, 'size' => 'half',
            'enabled' => true, 'can_hide' => true, 'contact_filter' => 'any',
        ]);

        $this->actingAs($admin)->get('/backoffice/account-dashboard-widgets')
            ->assertOk()->assertSee('Виджеты дашборда');
        $this->get('/backoffice/account-dashboard-widgets/'.$widget->id.'/edit')
            ->assertOk();
        $this->get('/backoffice/account-dashboard-widgets/'.$custom->id.'/edit')
            ->assertForbidden();
        $this->actingAs($super)
            ->get('/backoffice/account-dashboard-widgets/'.$custom->id.'/edit')
            ->assertOk();
        $this->assertSame(2, AccountDashboardWidget::query()
            ->whereIn('id', [$widget->id, $custom->id])->count());
    }
}
