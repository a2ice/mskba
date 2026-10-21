<?php

namespace Tests\Feature;

use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderPage;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderScriptAsset;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PageBuilderCodeModalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    private function publishedHome(): PageBuilderPage
    {
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        app(PageBuilderPublisher::class)->publish($page);

        return $page->refresh();
    }

    public function test_css_modal_publication_does_not_publish_pending_html(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $publisher = app(PageBuilderPublisher::class);
        $page = $this->publishedHome();
        $publishedHtml = $page->published_html;
        $page->update(['draft_html' => '<h1>Ещё не опубликован</h1>']);

        $this->assertTrue($publisher->saveAndPublishCss($page, '.cta { white-space: nowrap; }'));
        $page->refresh();
        $this->assertSame('.cta { white-space: nowrap; }', $page->draft_css);
        $this->assertSame('.cta { white-space: nowrap; }', $page->published_css);
        $this->assertSame($publishedHtml, $page->published_html);
        $this->assertSame('<h1>Ещё не опубликован</h1>', $page->draft_html);

        $publisher->saveAndPublishCss($page, '.cta { white-space: normal; }');
        $this->assertSame('.cta { white-space: nowrap; }', $page->refresh()->previous_css);
        $publisher->rollbackCss($page);
        $page->refresh();
        $this->assertSame('.cta { white-space: nowrap; }', $page->published_css);
        $this->assertSame($publishedHtml, $page->published_html);
        $this->assertSame(1, $page->has_previous_css ? 1 : 0);
    }

    public function test_css_on_unpublished_page_only_saves_draft(): void
    {
        $page = PageBuilderPage::query()->create([
            'name' => 'Новая страница',
            'path' => '/new-page',
            'draft_html' => '<h1>Черновик</h1>',
        ]);
        $published = app(PageBuilderPublisher::class)
            ->saveAndPublishCss($page, '.cta { min-width: 175px; }');
        $this->assertFalse($published);
        $page->refresh();
        $this->assertSame('.cta { min-width: 175px; }', $page->draft_css);
        $this->assertNull($page->published_css);
        $this->assertNull($page->published_at);
    }

    public function test_js_modal_save_publish_enable_and_rollback_are_atomic(): void
    {
        $admin = $this->admin();
        $page = $this->publishedHome();
        $service = app(PageBuilderScriptVersionStore::class);
        $script = $service->savePublishAndEnable($admin, $page, 'console.log("first");');
        $this->assertTrue($script->is_enabled);
        $this->assertSame('console.log("first");', $script->published_js);
        $this->assertNull($script->previous_hash);

        $service->setEnabled($admin, $page, false);
        $this->assertFalse($script->refresh()->is_enabled);
        $service->savePublishAndEnable($admin, $page, 'console.log("second");');
        $script->refresh();
        $this->assertSame('console.log("second");', $script->published_js);
        $this->assertSame('console.log("first");', $script->previous_js);
        $this->assertTrue($script->is_enabled);

        $service->rollbackAndEnable($admin, $page);
        $script->refresh();
        $this->assertSame('console.log("first");', $script->published_js);
        $this->assertSame('console.log("second");', $script->previous_js);
        $this->assertTrue($script->is_enabled);
        $this->assertSame(1, PageBuilderScriptAsset::query()->count());
    }

    public function test_empty_js_cannot_be_accidentally_published_and_enabled(): void
    {
        $admin = $this->admin();
        $page = $this->publishedHome();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PageBuilderScriptVersionStore::class)->savePublishAndEnable($admin, $page, '');
    }
    public function test_filament_code_buttons_open_modals_and_publish_separately(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $page = $this->publishedHome();

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->assertActionVisible('editBuilderCss')
            ->assertActionVisible('editBuilderJs')
            ->assertActionDoesNotExist('publish')
            ->assertActionDoesNotExist('rollbackCss')
            ->mountAction('editBuilderCss')
            ->assertMountedActionModalSee('Исходный CSS');

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->callAction('editBuilderCss', data: [
                'source' => '.modal-cta { white-space: nowrap; }',
            ])
            ->assertHasNoFormErrors();
        $this->assertSame('.modal-cta { white-space: nowrap; }', $page->refresh()->published_css);

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->mountAction('editBuilderJs')
            ->assertMountedActionModalSee('Исходный JavaScript');

        Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
            ->callAction('editBuilderJs', data: [
                'source' => 'console.log("unified modal");',
            ])
            ->assertHasNoFormErrors();

        $script = PageBuilderScriptAsset::query()->firstOrFail();
        $this->assertTrue($script->is_enabled);
        $this->assertSame('console.log("unified modal");', $script->published_js);
    }


    public function test_code_modals_have_bounded_code_scrolling_without_changing_publishing(): void
    {
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $page = $this->publishedHome();

        $this->get('/backoffice/page-builder/builder-pages/'.$page->id.'/edit')
            ->assertOk()
            ->assertSee('max-height: 95dvh', false)
            ->assertSee('.mskba-code-modal .mskba-code-editor .cm-editor', false);

        foreach (['editBuilderCss', 'editBuilderJs'] as $action) {
            $mounted = Livewire::test(EditBuilderPage::class, ['record' => $page->getRouteKey()])
                ->mountAction($action)
                ->assertMountedActionModalSee($action === 'editBuilderCss' ? 'Исходный CSS' : 'Исходный JavaScript');

            $modal = $mounted->instance()->getMountedAction();
            $this->assertSame('mskba-code-modal', $modal->getExtraModalWindowAttributes()['class'] ?? null);
            $this->assertTrue($modal->isModalHeaderSticky());
            $this->assertTrue($modal->isModalFooterSticky());
        }
    }
}
