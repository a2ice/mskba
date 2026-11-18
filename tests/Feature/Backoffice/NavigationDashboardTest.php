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

        $this->assertNotContains('Разработка', $labels);
        $this->assertTrue($groups->every(fn ($group): bool => $group->isCollapsed()));

        Livewire::test(NavigationOverview::class)
            ->assertSee('Соревнования')
            ->assertSee('Площадки')
            ->assertSee('Аренда и документы');
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
