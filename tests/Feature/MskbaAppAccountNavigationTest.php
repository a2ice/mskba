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
        $profile->assertSee('Управляй фотографией, публичным никнеймом и личными данными.');
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

    /** @return array<string, mixed>|null */
    private function roleGroup(UserParticipationRoleEnum $role): ?array
    {
        return collect(app(MenuResolver::class)->resolve('account'))
            ->first(fn (array $item): bool => ($item['role'] ?? null) === $role->value);
    }

    /** @return list<string> */
    private function roleLinks(UserParticipationRoleEnum $role): array
    {
        $group = $this->roleGroup($role);

        return $group === null ? [] : array_column($group['children'], 'label');
    }

    public function test_account_without_roles_has_no_participation_groups_and_wallet_precedes_settings(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertDontSee('data-account-role-group=', false)
            ->assertDontSee('Мой MSKBA')
            ->assertSee('Здесь ты можешь управлять');

        $this->assertSame([], array_filter(app(MenuResolver::class)->resolve('account'), fn ($item) => isset($item['role'])));
        $labels = array_column(app(MenuResolver::class)->resolve('account'), 'label');
        $this->assertSame(array_search('Кошелёк', $labels, true) + 1, array_search('Настройки', $labels, true));
    }

    public function test_player_role_has_its_own_group_with_settings_even_without_related_objects(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER);
        $this->actingAs($user)->get(route('account'))->assertOk()
            ->assertSee('data-account-role-group="player"', false)
            ->assertDontSee('Мой MSKBA');

        $this->assertSame(['Мои команды', 'Мои игры', 'Мои тренировки', 'Параметры'], $this->roleLinks(UserParticipationRoleEnum::PLAYER));
        $group = $this->roleGroup(UserParticipationRoleEnum::PLAYER);
        $this->assertSame('Игрок', $group['label']);
        $this->assertSame(route('account.participation-role', ['role' => 'player']), $group['children'][3]['url']);
        $this->assertFalse($group['active']);

        $this->get(route('account.my-games'))->assertOk()->assertSee('id="account-section-title"', false);
        $this->get(route('account.my-trainings'))->assertOk();
        $this->get(route('account.teams'))->assertOk();
    }

    public function test_role_settings_page_opens_correct_group_and_highlights_only_its_settings(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER);
        $this->giveRole($user, UserParticipationRoleEnum::REFEREE);

        $response = $this->actingAs($user)->get(route('account.participation-role', ['role' => 'player']))->assertOk();
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//details[@data-account-role-group="player"][@open]')->length);
        $this->assertSame(2, $xpath->query('//details[@data-account-role-group="referee"][not(@open)]')->length);
        $this->assertSame(2, $xpath->query('//details[@data-account-role-group="player"]//a[@href="'.route('account.participation-role', ['role' => 'player']).'"][@aria-current="page"]')->length);
        $this->assertFalse(collect(app(MenuResolver::class)->resolve('account'))->firstWhere('label', 'Роли в проекте')['active']);
        $this->assertTrue($this->roleGroup(UserParticipationRoleEnum::PLAYER)['active']);
        $this->assertFalse($this->roleGroup(UserParticipationRoleEnum::REFEREE)['active']);
        $this->assertTrue(collect($this->roleGroup(UserParticipationRoleEnum::PLAYER)['children'])->last()['active']);
        $this->assertSame(1, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " app-account-nav--mobile ")][@open]')->length);
    }

    public function test_multiple_roles_create_separate_groups_with_expected_overlaps_and_stable_order(): void
    {
        config()->set('features.sports_sections.enabled', true);
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER);
        $this->giveRole($user, UserParticipationRoleEnum::COACH);
        $this->giveRole($user, UserParticipationRoleEnum::REFEREE);

        $this->actingAs($user)->get(route('account.my-games'))->assertOk()
            ->assertSee('data-account-role-group="player"', false)
            ->assertSee('data-account-role-group="coach"', false)
            ->assertSee('data-account-role-group="referee"', false);
        $this->assertSame(['Мои команды', 'Мои игры', 'Мои тренировки', 'Параметры'], $this->roleLinks(UserParticipationRoleEnum::PLAYER));
        $this->assertSame(['Мои секции', 'Мои тренировки', 'Мои команды', 'Параметры'], $this->roleLinks(UserParticipationRoleEnum::COACH));
        $this->assertSame(['Мои игры', 'Судейские назначения', 'Параметры'], $this->roleLinks(UserParticipationRoleEnum::REFEREE));
        $this->assertTrue($this->roleGroup(UserParticipationRoleEnum::PLAYER)['active']);
        $this->assertFalse($this->roleGroup(UserParticipationRoleEnum::COACH)['active']);
        $this->assertTrue($this->roleGroup(UserParticipationRoleEnum::REFEREE)['active']);
        $groupRoles = array_values(array_filter(array_column(app(MenuResolver::class)->resolve('account'), 'role')));
        $this->assertSame(['player', 'coach', 'referee'], $groupRoles);
    }

    public function test_venue_representative_links_follow_feature_configuration(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::VENUE_RELATED);
        $this->actingAs($user)->get(route('account'))->assertOk();
        $this->assertSame(['Мои площадки', 'Бронирования', 'Расписание', 'Параметры'], $this->roleLinks(UserParticipationRoleEnum::VENUE_RELATED));
        $this->assertSame('Представитель площадки', $this->roleGroup(UserParticipationRoleEnum::VENUE_RELATED)['label']);
        $this->get(route('account.my-bookings'))->assertOk();
        $this->get(route('account.venue-schedule'))->assertOk();
    }

    public function test_all_remaining_roles_have_individual_groups(): void
    {
        foreach ([
            UserParticipationRoleEnum::ORGANIZER->value => ['Мои мероприятия', 'Мои турниры', 'Параметры'],
            UserParticipationRoleEnum::REFEREE->value => ['Мои игры', 'Судейские назначения', 'Параметры'],
            UserParticipationRoleEnum::STATISTICIAN->value => ['Мои игры', 'Статистика', 'Параметры'],
            UserParticipationRoleEnum::MEDIA->value => ['Мои материалы', 'Мои мероприятия', 'Параметры'],
        ] as $value => $expected) {
            $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
            $role = UserParticipationRoleEnum::from($value);
            $this->giveRole($user, $role);
            $this->actingAs($user)->get(route('account'))->assertOk();
            $this->assertSame($role->label(), $this->roleGroup($role)['label']);
            $this->assertSame($expected, $this->roleLinks($role));
        }
    }

    public function test_inactive_role_does_not_create_group(): void
    {
        $user = User::factory()->create(['personal_data_distribution_required_at' => now()]);
        $this->giveRole($user, UserParticipationRoleEnum::PLAYER, UserParticipationRoleStatusEnum::INACTIVE);
        $this->actingAs($user)->get(route('account'))->assertOk();
        $this->assertNull($this->roleGroup(UserParticipationRoleEnum::PLAYER));
    }

    public function test_new_role_pages_do_not_change_legacy_theme(): void
    {
        $this->setActiveTheme('mskba_dark');
        $this->actingAs(User::factory()->create())->get(route('account.my-games'))
            ->assertRedirect(route('account'));
    }
}
