<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderLqipManifest;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageBuilderAutoLqipTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_exposes_existing_sidecars_from_html_and_css_without_editing_snapshots(): void
    {
        Storage::fake('public');
        $manager = app(SiteMediaManager::class);
        $player = $manager->upload(UploadedFile::fake()->image('player.png', 240, 400));
        $background = $manager->upload(UploadedFile::fake()->image('tournaments.jpg', 360, 200));
        $noPreview = $manager->upload(UploadedFile::fake()->image('missing.jpg', 320, 180));
        Storage::disk('public')->delete(SiteMediaLqip::path($noPreview->currentMedia));

        $html = '<section class="courts"><img src="'.$player->publicUrl().'" alt="Игрок">'
            .'<img src="'.$noPreview->publicUrl().'" alt="Без миниатюры"></section>';
        $css = '.tournaments { background-image: linear-gradient(#fff8,#fff0), url("'
            .$background->publicUrl().'"); }';
        $page = PageBuilderPage::query()->create([
            'name' => 'Проверка LQIP', 'path' => '/lqip-check',
            'draft_html' => $html, 'draft_css' => $css,
        ]);
        app(PageBuilderPublisher::class)->publish($page);
        $page->refresh();
        $htmlBefore = $page->published_html;
        $cssBefore = $page->published_css;

        $result = app(PageBuilderRenderer::class)->render($page);
        $this->assertSame([
            $player->uuid => $player->lqipUrl(),
            $background->uuid => $background->lqipUrl(),
        ], $result['lqip']);
        $this->assertStringContainsString($player->publicUrl(), $result['html']);
        $this->assertStringContainsString($background->publicUrl(), $result['css']);
        $this->assertStringNotContainsString('data-site-media-lqip', $result['html']);
        $this->assertSame($htmlBefore, $page->fresh()->published_html);
        $this->assertSame($cssBefore, $page->fresh()->published_css);

        $this->withoutVite()->get('/lqip-check')->assertOk()
            ->assertSee('data-page-builder-lqip-manifest', false)
            ->assertSee($player->lqipUrl())
            ->assertSee($background->lqipUrl())
            ->assertDontSee($noPreview->publicUrl().'/lqip');
    }

    public function test_no_preview_no_media_and_external_urls_do_not_add_manifest_or_requests(): void
    {
        Storage::fake('public');
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('old.png', 200, 100));
        $manifest = app(PageBuilderLqipManifest::class);

        $this->assertSame([], $manifest->forContent('<img src="/images/ball-rolling.png">', '.ball { background:url("/images/ball-rolling.png"); }'));
        Storage::disk('public')->delete(SiteMediaLqip::path($asset->currentMedia));
        $this->assertSame([], $manifest->forContent('<img src="'.$asset->publicUrl().'">', ''));
        $this->assertSame([], $manifest->forContent('<img src="/media/site/00000000-0000-4000-8000-000000000000">', ''));
    }

    public function test_preview_is_resolved_from_current_version_after_replace_or_restore(): void
    {
        Storage::fake('public');
        $manager = app(SiteMediaManager::class);
        $asset = $manager->upload(UploadedFile::fake()->image('one.png', 280, 180));
        $first = $asset->currentMedia;
        $asset = $manager->replace($asset, UploadedFile::fake()->image('two.jpg', 300, 200));
        $last = $asset->currentMedia;
        $manifest = app(PageBuilderLqipManifest::class);
        $src = '<img src="'.$asset->publicUrl().'">';

        Storage::disk('public')->delete(SiteMediaLqip::path($last));
        $this->assertSame([], $manifest->forContent($src, ''));
        $asset = $manager->restore($asset);
        $this->assertSame($first->id, $asset->current_media_id);
        $this->assertSame([$asset->uuid => $asset->lqipUrl()], $manifest->forContent($src, ''));
    }
    public function test_lazy_tournaments_scene_named_artwork_is_available_in_manifest_without_republishing_js(): void
    {
        Storage::fake('public');
        $folder = SiteMediaFolder::query()->create([
            'name' => 'Турниры', 'slug' => 'home-tournaments-section',
        ]);
        $manager = app(SiteMediaManager::class);
        $background = $manager->upload(
            UploadedFile::fake()->image('background.jpg', 360, 200), $folder->id, 'background',
        );
        $ball = $manager->upload(
            UploadedFile::fake()->image('ball.png', 150, 150), $folder->id, 'ball',
        );
        $unused = $manager->upload(
            UploadedFile::fake()->image('other.png', 150, 150), $folder->id, 'other',
        );

        $builder = app(PageBuilderLqipManifest::class);
        $this->assertSame([], $builder->forContent('<section class="unrelated"></section>', ''));
        $map = $builder->forContent('<section class="mskba-tournaments"></section>', '');
        $this->assertSame($background->lqipUrl(), $map[$background->uuid] ?? null);
        $this->assertSame($ball->lqipUrl(), $map[$ball->uuid] ?? null);
        $this->assertArrayNotHasKey($unused->uuid, $map);
    }

}
