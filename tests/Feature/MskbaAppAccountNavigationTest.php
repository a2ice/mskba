<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Navigation\MenuResolver;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class MskbaAppAccountNavigationTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTheme = (string) config('themes.active');
        $this->setActiveTheme('mskba_app');
    }

    protected function tearDown(): void
    {
        $this->setActiveTheme($this->previousTheme);
        parent::tearDown();
    }

    private function setActiveTheme(string $theme): void
    {
        config()->set('themes.active', $theme);
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/'.$theme.'/views'));
    }

    public function test_app_account_overview_and_profile_have_distinct_routes_and_active_items(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);

        $overview = $this->actingAs($user)->get(route('account'))->assertOk();
        $overview->assertSee('Привет,');
        $this->assertStringContainsString('aria-current="page"', $overview->getContent());

        $items = app(MenuResolver::class)->resolve('account');
        $this->assertSame('Обзор', $items[0]['label']);
        $this->assertSame(route('account'), $items[0]['url']);
        $this->assertTrue($items[0]['active']);
        $this->assertSame('Профиль', $items[1]['label']);
        $this->assertSame(route('account.profile'), $items[1]['url']);
        $this->assertFalse($items[1]['active']);

        $profile = $this->actingAs($user)->get(route('account.profile'))->assertOk();
        $profile->assertSee('Личные данные и оформление профиля.');
        $this->assertStringContainsString('aria-label="Навигационная цепочка"', $profile->getContent());

        $profileItems = app(MenuResolver::class)->resolve('account');
        $this->assertFalse($profileItems[0]['active']);
        $this->assertTrue($profileItems[1]['active']);
    }

    public function test_top_level_account_sections_render_the_new_theme_skeleton(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);

        foreach ([
            'account.profile',
            'account.wallet',
            'account.roles',
            'account.teams',
            'account.notifications',
            'account.contacts',
            'account.settings',
        ] as $name) {
            $response = $this->actingAs($user)->get(route($name));
            $response->assertOk()
                ->assertSee($name === 'account.teams' ? 'id="account-teams-heading"' : 'id="account-section-title"', false);
            $response->assertDontSee('View for page');
        }
    }

    public function test_legacy_account_keeps_original_profile_menu_and_new_route_redirects_to_old_page(): void
    {
        $this->setActiveTheme('mskba_dark');
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('account.profile'))
            ->assertRedirect(route('account'));

        $items = app(MenuResolver::class)->resolve('account');
        $this->assertSame('Профиль', $items[0]['label']);
        $this->assertSame(route('account'), $items[0]['url']);
    }

    private function giveRole(User $user, UserParticipationRoleEnum $role, UserParticipationRoleStatusEnum $status = UserParticipationRoleStatusEnum::ACTIVE): void
    {
        $user->participationRoles(false)->create([
            'role' => $role,
            'status' => $status,
            'assigned_at' => now(),
            'assigned_by' => $user->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
    }

    /** @return array<int, string> */
    private function childLabels(): array
    {
        $group = collect(app(MenuResolver::class)->resolve('account'))->firstWhere('label', 'Мой MSKBA');

        return $group === null ? [] : array_column($group['children'], 'label');
    }

    public function test_account_without_roles_has_no_group_and_wallet_precedes_settings(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
            'personal_data_distribution_setup_completed_at' => null,
        ]);
        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertDontSee('Мой MSKBA')
            ->assertSee('Здесь ты можешь управлять');

        $this->assertSame([], $this->childLabels());
        $labels = array_column(app(MenuResolver::class)->resolve('account'), 'label');
        $this->assertSame(array_search('Кошелёк', $labels, true) + 1, array_search('Настройки', $labels, true));
    }

    public function test_player_role_shows_all_destinations_without_any_team_or_games(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER);

        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('Мой MSKBA')
            ->assertSee('Мои команды')
            ->assertSee('Мои игры')
            ->assertSee('Мои тренировки');
        $this->assertSame(['Мои команды', 'Мои игры', 'Мои тренировки'], $this->childLabels());

        $this->get(route('account.my-games'))->assertOk()
            ->assertSee('id="account-section-title"', false);
        $this->get(route('account.my-trainings'))->assertOk();
        $this->get(route('account.teams'))->assertOk();
    }

    public function test_multiple_roles_share_overlapping_sections_without_duplicates_and_open_active_group(): void
    {
        config()->set('features.sports_sections.enabled', true);
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER);
        $this->giveRole($user, UserParticipationRoleEnum::COACH);
        $this->giveRole($user, UserParticipationRoleEnum::REFEREE);
        $this->actingAs($user)->get(route('account.my-games'))->assertOk()
            ->assertSee('app-account-nav__group', false);

        $this->assertSame([
            'Мои команды', 'Мои игры', 'Мои тренировки',
            'Мои секции', 'Судейские назначения',
        ], $this->childLabels());
        $group = collect(app(MenuResolver::class)->resolve('account'))->firstWhere('label', 'Мой MSKBA');
        $this->assertTrue($group['active']);
        $this->assertSame(1, collect($group['children'])->where('active', true)->count());
    }

    public function test_venue_representative_sees_sections_before_owning_a_venue(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::VENUE_RELATED);
        $this->actingAs($user)->get(route('account'))->assertOk();
        $this->assertSame(['Мои площадки', 'Бронирования', 'Расписание'], $this->childLabels());

        $this->get(route('account.my-bookings'))->assertOk();
        $this->get(route('account.venue-schedule'))->assertOk();
    }

    public function test_all_remaining_roles_have_agreed_navigation(): void
    {
        foreach ([
            UserParticipationRoleEnum::ORGANIZER->value => ['Мои мероприятия', 'Мои турниры'],
            UserParticipationRoleEnum::REFEREE->value => ['Мои игры', 'Судейские назначения'],
            UserParticipationRoleEnum::STATISTICIAN->value => ['Мои игры', 'Статистика'],
            UserParticipationRoleEnum::MEDIA->value => ['Мои материалы', 'Мои мероприятия'],
        ] as $role => $expected) {
            $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
            $this->giveRole($user, UserParticipationRoleEnum::from($role));
            $this->actingAs($user)->get(route('account'))->assertOk();
            $this->assertSame($expected, $this->childLabels());
        }
    }

    public function test_inactive_role_does_not_add_sections(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER, UserParticipationRoleStatusEnum::INACTIVE);
        $this->actingAs($user)->get(route('account'))->assertOk();
        $this->assertSame([], $this->childLabels());
    }

    public function test_new_role_pages_do_not_change_legacy_theme(): void
    {
        $this->setActiveTheme('mskba_dark');
        $this->actingAs(User::factory()->create())->get(route('account.my-games'))
            ->assertRedirect(route('account'));
    }
}
