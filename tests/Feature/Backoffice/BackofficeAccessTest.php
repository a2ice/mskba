<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\CreateAccountDashboardWidget;
use App\Filament\Backoffice\Resources\AccountDashboardWidgets\Pages\EditAccountDashboardWidget;
use App\Filament\Backoffice\Resources\DashboardButtonStyles\Pages\CreateDashboardButtonStyle;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
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
        // Unauthorized custom_html records are excluded from the resource query.
        $this->get('/backoffice/account-dashboard-widgets/'.$custom->id.'/edit')
            ->assertNotFound();
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
                'gradient_color' => '#22CC88',
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
        $this->assertSame('#22CC88', $widget->gradient_color);
        $this->assertSame(['confirmed'], $widget->account_statuses);
        $this->assertSame([10, 11], $widget->include_user_ids);
        $this->assertSame([7], $widget->exclude_user_ids);
    }

    public function test_filament_saves_editable_status_html_for_confirmed_and_unconfirmed_accounts(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $widget = AccountDashboardWidget::query()->where('kind', 'account_status')->firstOrFail();

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->getRouteKey()])
            ->fillForm([
                'status_confirmed_html' => '<p>Готово! <a href="/faq" class="button primary">FAQ</a></p>',
                'status_unconfirmed_html' => '<p>Заполните профиль и подтвердите аккаунт.</p>',
                'status_blocked_html' => '<p>Напишите в поддержку.</p>',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $widget->refresh();
        $this->assertStringContainsString('Готово!', $widget->status_confirmed_html);
        $this->assertStringContainsString('подтвердите аккаунт', $widget->status_unconfirmed_html);
        $this->assertStringContainsString('поддержку', $widget->status_blocked_html);
    }


    public function test_repair_migration_restores_historical_missing_image_column_and_is_idempotent(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('account_dashboard_widgets', 'image_url'));

        \Illuminate\Support\Facades\Schema::table('account_dashboard_widgets', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropColumn('image_url');
        });
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('account_dashboard_widgets', 'image_url'));

        $migration = require database_path('migrations/2026_10_14_230000_repair_dashboard_widget_image_url_column.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('account_dashboard_widgets', 'image_url'));
        $widget = AccountDashboardWidget::query()->create([
            'kind' => 'advertisement', 'title' => 'Проверка изображения',
            'image_url' => 'https://example.org/image.jpg',
            'link_url' => 'https://example.org',
            'enabled' => true, 'can_hide' => true, 'size' => 'half',
            'contact_filter' => 'any', 'sort_order' => 20,
        ]);
        $this->assertSame('https://example.org/image.jpg', $widget->fresh()->image_url);
    }


    public function test_filament_accepts_internal_advertising_link(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(CreateAccountDashboardWidget::class)
            ->fillForm([
                'kind' => 'advertisement',
                'title' => 'Внутренний раздел партнёров',
                'link_url' => '/partners?ref=dashboard',
                'link_label' => 'Наши партнёры',
                'sort_order' => 42,
                'size' => 'half',
                'enabled' => true,
                'can_hide' => true,
                'contact_filter' => 'any',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $widget = AccountDashboardWidget::query()->where('title', 'Внутренний раздел партнёров')->firstOrFail();
        $this->assertSame('/partners?ref=dashboard', $widget->link_url);
    }

    public function test_filament_rejects_external_protocol_relative_and_script_links(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        foreach (['//evil.example/path', 'javascript:alert(1)'] as $unsafeUrl) {
            Livewire::test(CreateAccountDashboardWidget::class)
                ->fillForm([
                    'kind' => 'advertisement',
                    'title' => 'Небезопасная ссылка',
                    'link_url' => $unsafeUrl,
                    'sort_order' => 50,
                    'size' => 'half',
                    'enabled' => true,
                    'can_hide' => true,
                    'contact_filter' => 'any',
                ])
                ->call('create')
                ->assertHasFormErrors(['link_url']);
        }
        $this->assertDatabaseMissing('account_dashboard_widgets', ['title' => 'Небезопасная ссылка']);
    }


    public function test_only_superadmin_can_manage_button_styles_in_filament(): void
    {
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($admin)->get('/backoffice/dashboard-button-styles')->assertForbidden();
        $this->flushSession();
        $this->actingAs($super)->get('/backoffice/dashboard-button-styles')->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Livewire::test(CreateDashboardButtonStyle::class)
            ->fillForm([
                'name' => 'Зелёная кнопка',
                'class_name' => 'bg-green',
                'css_code' => '.bg-green { background-color: green !important; }',
                'enabled' => true,
            ])->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseHas('dashboard_button_styles', [
            'name' => 'Зелёная кнопка', 'class_name' => 'bg-green',
        ]);
    }

    public function test_administrator_can_edit_button_list_with_registered_classes(): void
    {
        DashboardButtonStyle::query()->create([
            'name' => 'Зелёный',
            'class_name' => 'bg-green',
            'css_code' => '.bg-green { background-color: green !important; }',
            'enabled' => true,
        ]);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $widget = AccountDashboardWidget::query()->where('kind', 'getting_started')->firstOrFail();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditAccountDashboardWidget::class, ['record' => $widget->getRouteKey()])
            ->fillForm([
                'actions' => [
                    ['label' => 'Партнёры', 'url' => '/partners', 'appearance' => 'primary',
                        'visibility' => 'always', 'classes' => ['bg-green']],
                    ['label' => 'Мои роли', 'url' => '/account/roles', 'appearance' => 'secondary',
                        'visibility' => 'confirmed', 'classes' => []],
                ],
            ])->call('save')->assertHasNoFormErrors();

        $this->assertSame('/partners', $widget->fresh()->actions[0]['url']);
        $this->assertSame(['bg-green'], $widget->fresh()->actions[0]['classes']);
        $this->assertCount(2, $widget->fresh()->actions);
    }

}
