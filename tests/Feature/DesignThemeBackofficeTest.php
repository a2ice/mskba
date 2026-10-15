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
            ->assertSee('Ночная арена');
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

        $this->actingAs($actor);
        $theme->update(['name' => 'Изменённое название']);
        $this->assertSame(2, $theme->revisions()->count());

        app(DesignThemeRevisionManager::class)->restore($theme, $baseline->id);
        $this->assertSame($original, $theme->fresh()->name);
        $this->assertSame(3, $theme->revisions()->count());
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
