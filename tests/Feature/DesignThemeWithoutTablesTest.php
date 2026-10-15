<?php

namespace Tests\Feature;

use App\Presentation\Theming\DesignThemeManager;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class DesignThemeWithoutTablesTest extends TestCase
{
    public function test_public_app_renders_default_theme_without_design_theme_tables(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $manager = app(DesignThemeManager::class);
        $this->assertSame('arena_night', $manager->default()->slug);

        $this->get(route('login'))->assertOk()
            ->assertSee('data-design-theme="arena_night"', false)
            ->assertSee('data-appearance-mode="dark"', false);

        $this->get($manager->cssUrl($manager->default()))
            ->assertOk()->assertSee('--bg:');
    }
}
