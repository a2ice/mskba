<?php

namespace Tests\Feature;

use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderPage;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PageBuilderCssAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delegated_html_publisher_cannot_publish_css_changes_without_css_publish(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $editor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::EDITOR,
        ]);
        app(BackofficePermissionManager::class)->sync($admin, $editor, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_BUILDER_VIEW->value,
            Permission::BACKOFFICE_BUILDER_EDIT->value,
            Permission::BACKOFFICE_BUILDER_PUBLISH->value,
            Permission::BACKOFFICE_BUILDER_CSS_VIEW->value,
            Permission::BACKOFFICE_BUILDER_CSS_EDIT->value,
        ]);
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update(['draft_css' => '.new-code { color: red; }']);

        $this->actingAs($editor);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->call('saveAndPublish')->assertForbidden();

        $this->assertNull($page->refresh()->published_css);
    }

    public function test_delegated_publisher_with_css_publish_may_publish_css(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $editor = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::EDITOR,
        ]);
        app(BackofficePermissionManager::class)->sync($admin, $editor, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_BUILDER_VIEW->value,
            Permission::BACKOFFICE_BUILDER_EDIT->value,
            Permission::BACKOFFICE_BUILDER_PUBLISH->value,
            Permission::BACKOFFICE_BUILDER_CSS_VIEW->value,
            Permission::BACKOFFICE_BUILDER_CSS_EDIT->value,
            Permission::BACKOFFICE_BUILDER_CSS_PUBLISH->value,
        ]);
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        $page->update(['draft_css' => '.new-code { color: red; }']);

        $this->actingAs($editor);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->call('saveAndPublish')->assertHasNoErrors();

        $this->assertSame('.new-code { color: red; }', $page->refresh()->published_css);
    }
}
