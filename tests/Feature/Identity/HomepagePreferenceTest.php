<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\HomepagePreference;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Content\Application\Services\PageBuilderRouteRegistry;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class HomepagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $this->withoutVite();
    }

    public function test_guest_gets_the_existing_homepage_and_original_alias_is_accessible(): void
    {
        $this->get('/')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'Cookie');
        $this->get(route('homepage.original'))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_authenticated_user_defaults_to_account_with_a_temporary_non_cached_redirect(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->homepage_preference);
        $this->actingAs($user)->get('/')->assertStatus(302)
            ->assertRedirect(route('account'))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'Cookie');
        $this->get(route('homepage.original'))->assertOk();
        $this->assertSame(HomepagePreference::DEFAULT, app(HomepagePreference::class)->selected($user));
    }

    public function test_account_owner_can_choose_all_allowlisted_destinations_and_current_home(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('account.settings'))->assertOk()
            ->assertSee('Главная страница')
            ->assertSee('Посмотреть главную MSKBA')
            ->assertSee('value="account" selected', false);

        $routes = [
            'account' => 'account',
            'venues' => 'venues',
            'events' => 'events.index',
            'news' => 'news.index',
            'shop' => 'shop.index',
        ];

        foreach ($routes as $preference => $route) {
            $this->patch(route('account.settings.homepage.update'), [
                'homepage_preference' => $preference,
            ])->assertRedirect(route('account.settings'));
            $this->assertSame($preference, $user->fresh()->homepage_preference);
            $this->get('/')->assertStatus(302)->assertRedirect(route($route));
        }

        $this->patch(route('account.settings.homepage.update'), [
            'homepage_preference' => 'current',
        ])->assertRedirect(route('account.settings'));
        $this->get('/')->assertOk();
        $this->get(route('homepage.original'))->assertOk();
    }

    public function test_bad_destinations_are_rejected_and_preferences_are_private(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();

        $this->actingAs($one)->patch(route('account.settings.homepage.update'), [
            'homepage_preference' => 'https://evil.example',
        ])->assertSessionHasErrors(['homepage_preference']);

        $this->patch(route('account.settings.homepage.update'), [
            'homepage_preference' => 'not-a-route',
        ])->assertSessionHasErrors(['homepage_preference']);
        $this->assertNull($one->fresh()->homepage_preference);

        $this->patch(route('account.settings.homepage.update'), [
            'homepage_preference' => 'shop',
        ])->assertRedirect(route('account.settings'));
        $this->get('/')->assertRedirect(route('shop.index'));
        $this->actingAs($two)->get('/')->assertRedirect(route('account'));
    }

    public function test_original_home_route_is_protected_from_page_builder_overrides(): void
    {
        $registry = app(PageBuilderRouteRegistry::class);
        $this->assertFalse($registry->inspect('/home')['eligible']);
        $this->assertTrue($registry->inspect('/home')['conflict']);
    }
}
