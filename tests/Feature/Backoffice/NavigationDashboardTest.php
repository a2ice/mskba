<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Pages\BackofficeDashboard;
use App\Filament\Backoffice\Widgets\NavigationOverview;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class NavigationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create([
            'system_role' => $role,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        return $user;
    }

    public function test_dashboard_uses_the_original_url_and_grouped_navigation(): void
    {
        $this->actingAs($this->admin(UserSystemRoleEnum::SUPERADMIN))
            ->get('/backoffice')
            ->assertOk()
            ->assertSee('Дашборд');

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->assertSame(
            route('filament.backoffice.pages.dashboard'),
            BackofficeDashboard::getUrl(),
        );

        $groups = collect(Filament::getNavigation())
            ->filter(fn ($group): bool => filled($group->getLabel()))
            ->values();

        $labels = $groups->map(fn ($group): string => (string) $group->getLabel())->all();

        foreach (['Контент', 'Сайт и дизайн', 'Пользователи и доступ', 'Площадки',
            'География', 'Аренда и документы', 'Соревнования', 'Магазин',
            'Лояльность', 'Поддержка'] as $label) {
            $this->assertContains($label, $labels);
        }

        $this->assertTrue($groups->every(fn ($group): bool => $group->isCollapsed()));

        Livewire::test(NavigationOverview::class)
            ->assertSee('Соревнования')
            ->assertSee('Площадки')
            ->assertSee('Аренда и документы')
            ->assertSee('mskba-bo-dashboard-icon', escape: false)
            ->assertSee('mskba-bo-dashboard-count', escape: false)
            ->assertSee('mskba-bo-dashboard-card', escape: false);
    }

    public function test_site_card_shows_venue_integration_and_dev_api_shortcuts(): void
    {
        $this->actingAs($this->admin(UserSystemRoleEnum::SUPERADMIN))
            ->get('/backoffice')->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(NavigationOverview::class)
            ->assertSee('Интеграции и API')
            ->assertSee('Интеграции площадок')
            ->assertSee('Выберите площадку')
            ->assertSee('REST API · Swagger')
            ->assertSee('OpenAPI 3.1 JSON')
            ->assertSee(route('backoffice.rest-api.openapi'), escape: false);

        $site = collect(Filament::getNavigation())
            ->first(fn ($group): bool => $group->getLabel() === 'Сайт и дизайн');

        $this->assertNotNull($site);
        $keys = collect($site->getItems())->map(fn ($item): string => $item->getKey())->all();
        $this->assertContains('backoffice.venue-integrations', $keys);
        $this->assertContains('backoffice.openapi', $keys);
    }

    public function test_production_site_card_points_to_dev_without_enabling_production_explorers(): void
    {
        $this->actingAs($this->admin(UserSystemRoleEnum::SUPERADMIN));
        app()->detectEnvironment(fn (): string => 'production');

        $this->get('/backoffice')->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(NavigationOverview::class)
            ->assertSee('REST API · Swagger (DEV)')
            ->assertSee('OpenAPI 3.1 JSON (DEV)')
            ->assertSee('https://dev.mskba.ru/backoffice/rest-api-explorer', escape: false)
            ->assertSee('https://dev.mskba.ru/backoffice/rest-api/openapi.json', escape: false);

        $this->get('/backoffice/rest-api-explorer')->assertForbidden();
        $this->get('/backoffice/rest-api/openapi.json')->assertForbidden();
    }

    public function test_dashboard_does_not_show_links_with_denied_permissions(): void
    {
        $this->actingAs($this->admin(UserSystemRoleEnum::MANAGER))
            ->get('/backoffice')
            ->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(NavigationOverview::class)
            ->assertDontSee('Материалы и FAQ');
    }
}
