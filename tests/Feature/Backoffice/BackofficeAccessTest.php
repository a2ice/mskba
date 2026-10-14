<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\CreateAccountDashboardWidget;
use Filament\Facades\Filament;
use Livewire\Livewire;
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
        // Filament's AuthenticateSession checks the current session identity.
        // Reset the test session before switching from admin to superadmin.
        $this->flushSession();
        $this->actingAs($super)
            ->get('/backoffice/account-dashboard-widgets/'.$custom->id.'/edit')
            ->assertOk();
        $this->assertSame(2, AccountDashboardWidget::query()
            ->whereIn('id', [$widget->id, $custom->id])->count());
    }
    public function test_filament_can_create_targeted_advertisement_without_replacing_existing_data(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateAccountDashboardWidget::class)
            ->fillForm([
                'kind' => 'advertisement',
                'title' => 'Баскетбольный магазин',
                'body' => 'Товары и услуги для игроков',
                'link_url' => 'https://example.org/catalog',
                'link_label' => 'Перейти в магазин',
                'image_url' => 'https://example.org/banner.jpg',
                'sort_order' => 15,
                'size' => 'half',
                'enabled' => true,
                'can_hide' => true,
                'contact_filter' => 'any',
                'account_statuses' => ['confirmed'],
                'system_roles' => [],
                'participation_roles' => [],
                'include_user_ids' => '10, 11',
                'exclude_user_ids' => '7',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $widget = AccountDashboardWidget::query()->where('title', 'Баскетбольный магазин')->firstOrFail();
        $this->assertSame('advertisement', $widget->kind);
        $this->assertSame(['confirmed'], $widget->account_statuses);
        $this->assertSame([10, 11], $widget->include_user_ids);
        $this->assertSame([7], $widget->exclude_user_ids);
    }

}
