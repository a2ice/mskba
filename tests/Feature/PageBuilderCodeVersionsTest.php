<?php

namespace Tests\Feature;

use App\Filament\Backoffice\Resources\PageBuilder\BuilderPageResource;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderRevision;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PageBuilderCodeVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    private function page(): PageBuilderPage
    {
        return PageBuilderPage::query()->where('path', '/')->firstOrFail();
    }

    public function test_only_two_full_page_publications_survive_with_compact_audit(): void
    {
        $page = $this->page();
        $publisher = app(PageBuilderPublisher::class);
        for ($i = 1; $i <= 4; $i++) {
            $page->update([
                'draft_html' => '<h1>Версия '.$i.'</h1>',
                'draft_css' => '.demo { margin: '.$i.'px; }',
            ]);
            $publisher->publish($page);
            $page->refresh();
        }

        $this->assertSame(2, PageBuilderRevision::query()->where('entity_type', 'page')
            ->where('entity_id', $page->id)->where('action', 'publish')->count());
        $this->assertSame(2, PageBuilderRevision::query()->where('entity_type', 'page')
            ->where('entity_id', $page->id)->where('action', 'publish_audit')->count());
        $previous = PageBuilderRevision::query()->where('entity_type', 'page')
            ->where('entity_id', $page->id)->where('action', 'publish')->orderBy('id')->firstOrFail();
        $this->assertSame('.demo { margin: 3px; }', $previous->snapshot['draft_css']);

        $audits = PageBuilderRevision::query()->where('action', 'publish_audit')->get();
        foreach ($audits as $audit) {
            $this->assertArrayNotHasKey('draft_css', $audit->snapshot);
            $this->assertArrayNotHasKey('draft_html', $audit->snapshot);
            $this->assertArrayHasKey('sha256', $audit->snapshot);
        }

        $publisher->restoreDraft($page, $previous->id);
        $this->assertSame('.demo { margin: 3px; }', $page->refresh()->draft_css);
        $this->assertSame('.demo { margin: 4px; }', $page->published_css);
    }

    public function test_previous_css_survives_html_only_publications_and_can_be_rolled_back(): void
    {
        $page = $this->page();
        $publisher = app(PageBuilderPublisher::class);

        $page->update(['draft_css' => '.box { color: red; }']);
        $publisher->publish($page);
        $page->refresh();
        $this->assertFalse($page->has_previous_css);

        $page->update(['draft_css' => '.box { color: blue; }']);
        $publisher->publish($page);
        $page->refresh();
        $this->assertSame('.box { color: red; }', $page->previous_css);
        $this->assertTrue($page->has_previous_css);

        for ($i = 0; $i < 4; $i++) {
            $page->update(['draft_html' => '<h1>HTML '. $i .'</h1>']);
            $publisher->publish($page);
            $page->refresh();
        }

        $this->assertSame('.box { color: red; }', $page->previous_css);
        $this->assertSame('.box { color: blue; }', $page->published_css);
        $currentHtml = $page->published_html;
        $publisher->rollbackCss($page);
        $page->refresh();
        $this->assertSame('.box { color: red; }', $page->published_css);
        $this->assertSame('.box { color: blue; }', $page->previous_css);
        $this->assertSame($currentHtml, $page->published_html);
        $this->assertSame('.box { color: red; }', $page->draft_css);
    }

    public function test_css_and_js_privileges_are_granular_and_delegable(): void
    {
        $admin = $this->admin();
        $editor = User::factory()->create([
            'system_role' => UserSystemRoleEnum::EDITOR,
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->assertTrue(BuilderPageResource::canViewCss());
        $this->assertTrue(BuilderPageResource::canEditCss());
        $this->assertTrue(BuilderPageResource::canPublishCss());
        $this->assertTrue(BuilderPageResource::canPublishJs());

        app(BackofficePermissionManager::class)->sync($admin, $editor, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_BUILDER_VIEW->value,
            Permission::BACKOFFICE_BUILDER_EDIT->value,
            Permission::BACKOFFICE_BUILDER_PUBLISH->value,
            Permission::BACKOFFICE_BUILDER_CSS_VIEW->value,
            Permission::BACKOFFICE_BUILDER_CSS_EDIT->value,
            Permission::BACKOFFICE_BUILDER_JS_VIEW->value,
        ]);
        $this->actingAs($editor);
        $this->assertTrue(BuilderPageResource::canEditCss());
        $this->assertFalse(BuilderPageResource::canPublishCss());
        $this->assertTrue(BuilderPageResource::canViewJs());
        $this->assertFalse(BuilderPageResource::canEditJs());
        $this->assertFalse(BuilderPageResource::canPublishJs());
    }

    public function test_js_store_keeps_only_current_and_previous_and_never_enables_runtime(): void
    {
        $admin = $this->admin();
        $store = app(PageBuilderScriptVersionStore::class);
        $page = $this->page();

        foreach (['console.log(1)', 'console.log(2)', 'console.log(3)'] as $source) {
            $store->saveDraft($admin, $page, $source);
            $store->publish($admin, $page);
        }
        $script = PageBuilderScriptAsset::query()->firstOrFail();
        $this->assertSame('console.log(3)', $script->published_js);
        $this->assertSame('console.log(2)', $script->previous_js);
        $this->assertSame(hash('sha256', 'console.log(3)'), $script->published_hash);
        $this->assertFalse($script->is_enabled);

        $store->rollback($admin, $page);
        $this->assertSame('console.log(2)', $script->refresh()->published_js);
        $this->assertSame('console.log(3)', $script->previous_js);
        $this->assertFalse($script->is_enabled);
    }

    public function test_user_without_explicit_js_permission_cannot_stage_code(): void
    {
        $admin = $this->admin();
        $editor = User::factory()->create([
            'system_role' => UserSystemRoleEnum::EDITOR,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        app(BackofficePermissionManager::class)->sync($admin, $editor, [
            Permission::BACKOFFICE_ACCESS->value,
            Permission::BACKOFFICE_BUILDER_EDIT->value,
        ]);
        $this->expectException(HttpException::class);
        app(PageBuilderScriptVersionStore::class)->saveDraft($editor, $this->page(), 'alert(1)');
    }
}
