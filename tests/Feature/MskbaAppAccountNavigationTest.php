<?php

namespace Tests\Feature;

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
            $response->assertOk()->assertSee('id="account-section-title"', false);
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
}
