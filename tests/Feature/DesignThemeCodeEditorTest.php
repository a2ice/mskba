<?php

namespace Tests\Feature;

use App\Filament\Backoffice\Resources\DesignThemes\Pages\EditDesignTheme;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeCodeManager;
use App\Presentation\Theming\DesignThemeManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DesignThemeCodeEditorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
    }

    public function test_existing_theme_editor_contains_css_and_js_actions_without_a_new_resource(): void
    {
        $actor = $this->admin();
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();

        $this->actingAs($actor)->get('/backoffice/design-themes/'.$theme->id.'/edit')
            ->assertOk()
            ->assertSee('editThemeCss', false)
            ->assertSee('editThemeJs', false)
            ->assertSee('Расширенные CSS-токены');
    }

    public function test_published_theme_css_is_global_versioned_and_independent_from_tokens(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $dark = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();
        $beforeTokens = $theme->css_code;
        $beforeThemeRevisions = $theme->revisions()->count();
        $urlBefore = app(DesignThemeManager::class)->cssUrl($theme);
        $manager = app(DesignThemeCodeManager::class);

        $manager->saveDraft($actor, $theme, 'css', '.bordered-plate { border-radius: 24px; }');
        $this->get($urlBefore)->assertOk()->assertDontSee('bordered-plate');

        $manager->saveAndPublish($actor, $theme, 'css', '.bordered-plate { border-radius: 24px; }');
        $newUrl = app(DesignThemeManager::class)->cssUrl($theme);
        $this->assertNotSame($urlBefore, $newUrl);
        $this->get($newUrl)->assertOk()->assertSee('.bordered-plate { border-radius: 24px; }');
        $this->get($urlBefore)->assertNotFound();
        $this->assertSame($beforeTokens, $theme->fresh()->css_code);
        $this->assertSame($beforeThemeRevisions, $theme->revisions()->count());
        $this->get(app(DesignThemeManager::class)->cssUrl($dark))
            ->assertOk()->assertDontSee('.bordered-plate { border-radius: 24px; }');

        $manager->disable($actor, $theme, 'css');
        $this->get(app(DesignThemeManager::class)->cssUrl($theme))
            ->assertOk()->assertDontSee('.bordered-plate { border-radius: 24px; }');
    }

    public function test_js_is_disabled_until_published_then_served_only_for_active_theme_and_hash(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $other = DesignTheme::query()->where('slug', 'arena_night')->firstOrFail();
        $manager = app(DesignThemeCodeManager::class);

        $this->assertNull(app(DesignThemeManager::class)->jsUrl($theme));
        $manager->saveDraft($actor, $theme, 'js', 'window.__mskbaThemeTest = 1;');
        $this->assertNull(app(DesignThemeManager::class)->jsUrl($theme));
        $manager->saveAndPublish($actor, $theme, 'js', 'window.__mskbaThemeTest = 1;');
        $url = app(DesignThemeManager::class)->jsUrl($theme);
        $this->assertNotNull($url);
        $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
            ->assertSee('window.__mskbaThemeTest = 1;');
        $this->assertNull(app(DesignThemeManager::class)->jsUrl($other));
        $this->get(str_replace('.js', '-stale.js', $url))->assertNotFound();

        $manager->disable($actor, $theme, 'js');
        $this->assertNull(app(DesignThemeManager::class)->jsUrl($theme));
        $this->get($url)->assertNotFound();
    }

    public function test_revision_restore_is_reversible_and_restores_the_selected_source(): void
    {
        $actor = $this->admin();
        $this->actingAs($actor);
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $manager = app(DesignThemeCodeManager::class);

        $first = $manager->saveAndPublish($actor, $theme, 'css', '.plate { padding: 10px; }');
        $firstRevision = $first->revisions()->latest('id')->firstOrFail();
        $manager->saveAndPublish($actor, $theme, 'css', '.plate { padding: 20px; }');
        $manager->restore($actor, $theme, 'css', $firstRevision->id);

        $this->get(app(DesignThemeManager::class)->cssUrl($theme))
            ->assertOk()->assertSee('.plate { padding: 10px; }')
            ->assertDontSee('.plate { padding: 20px; }');
        $this->assertSame(3, $first->revisions()->count());
    }

    public function test_rejects_unsafe_css_and_forbids_unprivileged_edits(): void
    {
        $actor = $this->admin();
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();
        $manager = app(DesignThemeCodeManager::class);
        $this->actingAs($actor);

        foreach (['@import "https://example.test/a.css";', 'body { color: red; </style>'] as $unsafe) {
            try {
                $manager->saveAndPublish($actor, $theme, 'css', $unsafe);
                $this->fail('Unsafe CSS accepted');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $ordinary = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $this->actingAs($ordinary);
        try {
            $manager->saveAndPublish($ordinary, $theme, 'css', '.test { color: red; }');
            $this->fail('Ordinary user could publish theme code');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_theme_css_and_js_modals_use_scrolling_code_editor_layout(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $theme = DesignTheme::query()->where('slug', 'court_day')->firstOrFail();

        foreach (['editThemeCss', 'editThemeJs'] as $action) {
            Livewire::test(EditDesignTheme::class, ['record' => $theme->getRouteKey()])
                ->mountAction($action)
                ->assertSee('mskba-code-modal', false)
                ->assertSee('mskba-code-editor', false);
        }
    }
}
