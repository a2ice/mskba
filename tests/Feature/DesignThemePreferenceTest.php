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
