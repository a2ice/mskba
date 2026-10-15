<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeManager;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DesignThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_installs_two_protected_system_themes(): void
    {
        $this->assertEqualsCanonicalizing(
            ['arena_night', 'court_day'],
            DesignTheme::query()->where('is_system', true)->pluck('slug')->all(),
        );
        $this->assertSame('arena_night', app(DesignThemeManager::class)->default()->slug);
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $this->assertSame('#F3F4F6', $day->colors['bg']);
        $this->assertSame('#F7F8FA', $day->colors['surface-raised']);
        $this->assertStringContainsString('--light-page-top: #F7F8FA;', $day->css_code);
        $this->assertSame('inter', $day->body_font);
        $this->assertSame('inter', $day->heading_font);
        $this->assertSame('#BCDDF6', $day->widget_glow_color);
        $this->assertSame(20, $day->widget_glow_strength);
        $this->assertSame('#FFFFFF', $day->sidebar_background);
        $this->assertSame('#FFF4EB', $day->sidebar_active_background);
    }

    public function test_blue_palette_upgrade_preserves_manual_filament_edits(): void
    {
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $colors = $day->colors;
        $colors['bg'] = '#F7F8FA';
        $colors['field'] = '#F8F9FA';
        $colors['surface-raised'] = '#DDEEFF'; // Edited by administrator.
        $day->update([
            'colors' => $colors,
            'css_code' => str_replace('--body-glow: #a4cef338;', '--body-glow: #bd4f000a;', $day->css_code),
        ]);
        $before = $day->revisions()->count();

        $migration = require database_path('migrations/2026_10_15_210000_refresh_court_day_ice_blue_palette.php');
        $migration->up();

        $day->refresh();
        $this->assertSame('#F3F4F6', $day->colors['bg']);
        $this->assertSame('#F8F9FA', $day->colors['field']);
        $this->assertSame('#DDEEFF', $day->colors['surface-raised']);
        $this->assertStringContainsString('--body-glow: #00000000;', $day->css_code);
        $this->assertSame($before + 1, $day->revisions()->count());
    }

    public function test_surface_palette_upgrade_keeps_custom_filament_values_and_creates_one_revision(): void
    {
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $colors = $day->colors;
        $colors['bg'] = '#EBF3FC';
        $colors['field'] = '#F4F9FF';
        $colors['surface-raised'] = '#DDEEFF'; // Custom override must survive.
        $css = str_replace('--body-glow: #c5daf030;', '--body-glow: #a4cef338;', $day->css_code);
        $css = preg_replace('/^\\s*--light-[a-z-]+:[^;]+;\\n?/m', '', $css);
        $day->update(['colors' => $colors, 'css_code' => $css]);
        $before = $day->revisions()->count();

        $migration = require database_path('migrations/2026_10_15_220000_polish_court_day_surface_palette.php');
        $migration->up();

        $day->refresh();
        $this->assertSame('#F3F4F6', $day->colors['bg']);
        $this->assertSame('#F8F9FA', $day->colors['field']);
        $this->assertSame('#DDEEFF', $day->colors['surface-raised']);
        $this->assertStringContainsString('--body-glow: #00000000;', $day->css_code);
        $this->assertStringContainsString('--light-card-blue: #FFFFFF;', $day->css_code);
        $this->assertSame($before + 1, $day->revisions()->count());

        $migration->up();
        $this->assertSame($before + 1, $day->revisions()->count());
    }

    public function test_social_neutral_upgrade_preserves_custom_filament_colors_and_is_idempotent(): void
    {
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $colors = $day->colors;
        $colors['bg'] = '#F8FAFE'; // Previous standard value.
        $colors['line'] = '#AAABAC'; // Administrator override.
        $colors['surface-raised'] = '#EDF4FC'; // Previous standard.
        $css = str_replace(
            '--light-page-bottom: #F1F2F4;',
            '--light-page-bottom: #eef5fc;',
            $day->css_code,
        );
        $css = str_replace(
            '--light-title-top: #18212B;',
            '--light-title-top: #334455;', // Administrator override.
            $css,
        );
        $day->update(['colors' => $colors, 'css_code' => $css]);
        $before = $day->revisions()->count();

        $migration = require database_path('migrations/2026_10_15_230000_social_neutral_court_day.php');
        $migration->up();

        $day->refresh();
        $this->assertSame('#F3F4F6', $day->colors['bg']);
        $this->assertSame('#F7F8FA', $day->colors['surface-raised']);
        $this->assertSame('#AAABAC', $day->colors['line']);
        $this->assertStringContainsString('--light-page-bottom: #F1F2F4;', $day->css_code);
        $this->assertStringContainsString('--light-title-top: #334455;', $day->css_code);
        $this->assertSame($before + 1, $day->revisions()->count());

        $migration->up();
        $this->assertSame($before + 1, $day->revisions()->count());
    }

    public function test_premium_typography_tokens_preserve_filament_choices_and_are_idempotent(): void
    {
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $css = str_replace(
            '--light-display-gloss-top: #435468;',
            '--light-display-gloss-top: #556677;', // Explicit admin override.
            $day->css_code,
        );
        $css = preg_replace('/^\\s*--light-premium-[a-z-]+:[^;]+;\\n?/m', '', $css);
        $day->update(['css_code' => $css]);
        $before = $day->revisions()->count();

        $migration = require database_path('migrations/2026_10_15_233000_add_court_day_premium_tokens.php');
        $migration->up();

        $day->refresh();
        $this->assertStringContainsString('--light-display-gloss-top: #556677;', $day->css_code);
        $this->assertStringContainsString('--light-premium-blur: 12px;', $day->css_code);
        $this->assertStringContainsString('--light-display-font-family: Inter, Arial, sans-serif;', $day->css_code);
        $this->assertSame($before + 1, $day->revisions()->count());

        $migration->up();
        $this->assertSame($before + 1, $day->revisions()->count());
    }

    public function test_theme_font_sidebar_and_glow_changes_are_versioned_and_reversible(): void
    {
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $initial = $day->revisions()->count();
        $revision = $day->revisions()->orderByDesc('id')->firstOrFail();

        $day->update([
            'body_font' => 'rubik',
            'heading_font' => 'manrope',
            'sidebar_hover' => '#E6EBF2',
            'widget_glow_strength' => 8,
        ]);
        $this->assertSame($initial + 1, $day->revisions()->count());

        $admin = \App\Modules\Identity\Domain\Models\User::factory()->create([
            'status' => \App\Modules\Identity\Domain\Enums\UserStatusEnum::CONFIRMED,
            'system_role' => \App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN,
        ]);
        $this->actingAs($admin);
        app(\App\Presentation\Theming\DesignThemeRevisionManager::class)->restore($day, $revision->id);

        $day->refresh();
        $this->assertSame('inter', $day->body_font);
        $this->assertSame('inter', $day->heading_font);
        $this->assertSame('#F7F8FA', $day->sidebar_hover);
        $this->assertSame(20, $day->widget_glow_strength);
        $this->assertSame($initial + 2, $day->revisions()->count());
    }

    public function test_system_themes_cannot_be_deleted_or_unpublished(): void
    {
        $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();

        try {
            $night->delete();
            $this->fail('System theme was deleted.');
        } catch (ValidationException $exception) {
            $this->assertTrue(DesignTheme::query()->whereKey($night->getKey())->exists());
        }

        $night->is_published = false;
        $night->save();
        $this->assertTrue($night->fresh()->is_published);
    }

    public function test_guest_receives_public_versioned_css(): void
    {
        $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();
        $url = app(DesignThemeManager::class)->cssUrl($night);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertSee('--bg:');
        $this->get(str_replace('.css', '-invalid.css', $url))->assertNotFound();
    }

    public function test_account_preference_overrides_global_theme_without_changing_it(): void
    {
        $user = User::factory()->create();
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $this->actingAs($user)->patchJson(route('account.settings.appearance.update'), [
            'design_theme_id' => $day->id,
        ])->assertOk()->assertJsonPath('theme', 'court_day');
        $this->assertSame('court_day', app(DesignThemeManager::class)->forUser($user->fresh())->slug);
        $this->assertSame('arena_night', app(DesignThemeManager::class)->default()->slug);
        $this->patchJson(route('account.settings.appearance.update'), [
            'design_theme_id' => null,
        ])->assertOk()->assertJsonPath('theme', 'arena_night');
    }

    public function test_account_settings_render_selected_appearance_before_paint(): void
    {
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));

        $user = User::factory()->create();
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $user->forceFill(['design_theme_id' => $day->id])->save();

        $this->actingAs($user)->get(route('account.settings'))
            ->assertOk()
            ->assertSee('data-design-theme="court_day"', false)
            ->assertSee('data-appearance-mode="light"', false)
            ->assertSee('name="design_theme_id"', false)
            ->assertSee('Оформление сайта');
    }

    public function test_unpublished_custom_theme_cannot_be_selected(): void
    {
        $user = User::factory()->create();
        $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();
        $draft = $night->replicate(['slug', 'is_system', 'is_published']);
        $draft->slug = 'test_unpublished';
        $draft->is_system = false;
        $draft->is_published = false;
        $draft->save();

        $this->actingAs($user)->patchJson(route('account.settings.appearance.update'), [
            'design_theme_id' => $draft->id,
        ])->assertUnprocessable();
        $this->assertNull($user->fresh()->design_theme_id);
        $this->get('/assets/design-themes/'.$draft->slug.'/0000000000000000.css')->assertNotFound();
    }
}
