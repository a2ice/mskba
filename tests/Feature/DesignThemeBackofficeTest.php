<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeRevisionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DesignThemeBackofficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_theme_resource_pages_and_preview(): void
    {
        $actor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();

        $this->actingAs($actor)->get('/backoffice/design-themes')->assertOk();
        $this->get('/backoffice/design-themes/create')->assertOk();
        $this->get('/backoffice/design-themes/'.$night->id.'/edit')->assertOk();
        $this->get(route('design-themes.preview', $night))->assertOk()
            ->assertSee('Ночная арена')
            ->assertSee('theme-preview .search-control input:focus-visible')
            ->assertSee('theme-preview__cool-panel');
    }

    public function test_light_preview_offers_six_styled_font_choices_and_untouched_dark_preview(): void
    {
        $actor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $day = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();

        $this->actingAs($actor)->get(route('design-themes.preview', $day))
            ->assertOk()
            ->assertSee('data-premium-font', false)
            ->assertSee('data-premium-gloss', false)
            ->assertSee('Onest — современный')
            ->assertSee('Manrope — геометричный')
            ->assertSee('Inter — универсальный')
            ->assertSee('Golos Text — лаконичный')
            ->assertSee('Commissioner — выразительный')
            ->assertSee('Rubik — мягкая геометрия')
            ->assertSee('role="listbox"', false)
            ->assertSee('theme-preview__display-title');

        $this->get(route('design-themes.preview', $night))
            ->assertOk()
            ->assertDontSee('data-premium-font', false);
    }

    public function test_premium_font_files_and_ofl_licenses_are_bundled_locally(): void
    {
        foreach (['onest', 'manrope', 'golos-text', 'commissioner', 'rubik'] as $family) {
            foreach (['latin', 'cyrillic'] as $subset) {
                $this->assertFileExists(resource_path(
                    "themes/mskba_app/assets/fonts/premium/{$family}-{$subset}-wght-normal.woff2"
                ));
            }
            $this->assertFileExists(resource_path(
                "themes/mskba_app/assets/fonts/premium/{$family}-OFL-LICENSE.txt"
            ));
        }
    }

    public function test_admin_can_restore_a_previous_theme_revision(): void
    {
        $actor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $original = $theme->name;
        $baseline = $theme->revisions()->firstOrFail();
        $initialCount = $theme->revisions()->count();

        $this->actingAs($actor);
        $theme->update(['name' => 'Изменённое название']);
        $this->assertSame($initialCount + 1, $theme->revisions()->count());

        app(DesignThemeRevisionManager::class)->restore($theme, $baseline->id);
        $this->assertSame($original, $theme->fresh()->name);
        $this->assertSame($initialCount + 2, $theme->revisions()->count());
    }

    public function test_ordinary_user_cannot_edit_or_preview_themes(): void
    {
        $actor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $night = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();

        $this->actingAs($actor)->get('/backoffice/design-themes')->assertForbidden();
        $this->get(route('design-themes.preview', $night))->assertForbidden();
    }
}
