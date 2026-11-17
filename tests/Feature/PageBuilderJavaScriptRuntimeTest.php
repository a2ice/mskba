<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Application\Services\PageBuilderScriptVersionStore;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Content\Domain\Models\PageBuilderTemplate;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageBuilderJavaScriptRuntimeTest extends TestCase
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
        $page = PageBuilderPage::query()->where('path', '/')->firstOrFail();
        app(PageBuilderPublisher::class)->publish($page);

        return $page->refresh();
    }

    public function test_scripts_are_opt_in_content_hashed_and_can_be_disabled(): void
    {
        $admin = $this->admin();
        $page = $this->page();
        $store = app(PageBuilderScriptVersionStore::class);
        $renderer = app(PageBuilderRenderer::class);

        $store->saveDraft($admin, $page, 'document.body.dataset.test = "draft";');
        $this->assertSame([], $renderer->render($page)['scripts']);
        $this->assertSame([], $renderer->render($page, true)['scripts']);

        $published = $store->publish($admin, $page);
        $hash1 = hash('sha256', (string) $published->published_js);
        $url1 = route('page-builder.script', ['type' => 'page', 'id' => $page->id, 'hash' => $hash1]);

        $this->get($url1)->assertNotFound();
        $store->setEnabled($admin, $page, true);
        $this->assertSame([$url1], $renderer->render($page)['scripts']);
        $this->assertSame([], $renderer->render($page, true)['scripts']);
        $this->get($url1)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public')
            ->assertSee('document.body.dataset.test = "draft";', false);

        $store->saveDraft($admin, $page, 'document.body.dataset.test = "new";');
        // Until published, the old code remains the only public version.
        $this->assertSame([$url1], $renderer->render($page)['scripts']);
        $store->publish($admin, $page);
        $hash2 = hash('sha256', 'document.body.dataset.test = "new";');
        $url2 = route('page-builder.script', ['type' => 'page', 'id' => $page->id, 'hash' => $hash2]);
        $this->assertSame([$url2], $renderer->render($page)['scripts']);
        $this->get($url1)->assertNotFound();
        $this->get($url2)->assertOk();

        $store->setEnabled($admin, $page, false);
        $this->assertSame([], $renderer->render($page)['scripts']);
        $this->get($url2)->assertNotFound();

        $store->rollback($admin, $page);
        $this->assertSame([], $renderer->render($page)['scripts']);
        $store->setEnabled($admin, $page, true);
        $this->assertSame([$url1], $renderer->render($page)['scripts']);
    }

    public function test_component_and_template_js_are_included_once_and_only_on_public_page(): void
    {
        $admin = $this->admin();
        $page = $this->page();
        $component = PageBuilderComponent::query()->create([
            'slug' => 'test-component', 'name' => 'Компонент',
            'draft_html' => '<p>Виджет</p>',
        ]);
        app(PageBuilderPublisher::class)->publish($component);
        $template = PageBuilderTemplate::query()->create([
            'slug' => 'test-template', 'name' => 'Шаблон',
            'draft_html' => '<section>[[page_content]]</section>',
        ]);
        app(PageBuilderPublisher::class)->publish($template);
        $page->update([
            'template_id' => $template->id,
            'draft_html' => '<mskba-component name="test-component"></mskba-component>'
                .'<mskba-component name="test-component"></mskba-component>',
        ]);
        app(PageBuilderPublisher::class)->publish($page);
        $page->refresh();

        $store = app(PageBuilderScriptVersionStore::class);
        foreach ([$page, $template, $component] as $owner) {
            $store->saveDraft($admin, $owner, 'console.log("'.$owner->getKey().'");');
            $store->publish($admin, $owner);
            $store->setEnabled($admin, $owner, true);
        }
        $output = app(PageBuilderRenderer::class)->render($page);
        $this->assertCount(3, $output['scripts']);
        $this->assertSame([], app(PageBuilderRenderer::class)->render($page, true)['scripts']);
    }

    public function test_draft_preview_is_restricted_and_sandboxed(): void
    {
        $admin = $this->admin();
        $page = $this->page();
        $src = '<script>window.parent.alert("bad")</script>';
        app(PageBuilderScriptVersionStore::class)->saveDraft($admin, $page, $src);
        $url = route('page-builder.js-preview', ['type' => 'page', 'id' => $page->id]);

        $this->get($url)->assertRedirect();
        $this->actingAs($admin);
        $response = $this->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('sandbox allow-scripts', $csp);
        $this->assertStringContainsString("connect-src 'none'", $csp);
        $this->assertStringNotContainsString('allow-same-origin', $csp);
        $this->assertStringNotContainsString($src, $response->getContent());
        $this->assertStringContainsString(base64_encode($src), $response->getContent());
    }
}
