<?php

namespace Tests\Feature;

use Tests\TestCase;

final class UiStudioAccessTest extends TestCase
{
    public function test_studio_routes_are_absent_in_normal_environment(): void
    {
        $this->assertFalse(app('router')->has('ui-studio.show'));
        $this->assertFalse(app('router')->has('ui-studio.asset'));
    }

    public function test_studio_requires_separate_credentials_and_exact_host(): void
    {
        $this->enableStudio();

        $this->get('http://other.example/uikit')->assertNotFound();
        $this->get('http://ui.mskba.test/uikit')->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="MSKBA UI Studio"');
        $this->withBasicAuth('reviewer', 'wrong')->get('http://ui.mskba.test/uikit')->assertUnauthorized();
        $this->withBasicAuth('reviewer', 'fixture-secret')->get('http://ui.mskba.test/uikit')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('MSKBA UI Studio')->assertSee('unified-chat');
    }

    public function test_compare_uses_identical_fixture_width_and_explicit_theme_identity(): void
    {
        $this->enableStudio();

        $this->withBasicAuth('reviewer', 'fixture-secret')
            ->get('http://ui.mskba.test/uikit/unified-chat?theme=arena_night&compare=court_day&width=390&state=empty')
            ->assertOk()->assertSee('arena_night')->assertSee('court_day')
            ->assertSee('width="390"', false)->assertSee('theme_hash=');
    }

    public function test_stale_theme_asset_revision_is_rejected(): void
    {
        $this->enableStudio();

        $this->withBasicAuth('reviewer', 'fixture-secret')
            ->get('http://ui.mskba.test/uikit/theme-assets/arena_night/'.str_repeat('0', 64).'/css')
            ->assertStatus(409);
    }

    private function enableStudio(): void
    {
        $this->app->instance('env', 'ui-studio');
        config()->set([
            'ui-studio.enabled' => true,
            'ui-studio.host' => 'ui.mskba.test',
            'ui-studio.user' => 'reviewer',
            'ui-studio.password_hash' => password_hash('fixture-secret', PASSWORD_BCRYPT),
        ]);
        require base_path('routes/ui-studio.php');
        app('router')->getRoutes()->refreshNameLookups();
    }
}
