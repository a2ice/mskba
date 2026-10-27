<?php

namespace Tests\Feature;

use App\Modules\Content\Application\Services\PageBuilderLqipManifest;
use App\Modules\Content\Application\Services\PageBuilderFirstPaintLqip;
use App\Modules\Content\Application\Services\PageBuilderPublisher;
use App\Modules\Content\Application\Services\PageBuilderRenderer;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Presentation\Theming\ThemeResolver;
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
        // Exercise native first-paint on the theme that includes the shared
        // Page Builder script stack; the default legacy theme is kept intact.
        $theme = config('themes');
        $theme['active'] = 'mskba_app';
        $this->app->instance(ThemeResolver::class, new ThemeResolver($theme));
        view()->getFinder()->replaceNamespace('theme', resource_path('themes/mskba_app/views'));
        $htmlBefore = $page->published_html;
        $cssBefore = $page->published_css;

        $result = app(PageBuilderRenderer::class)->render($page);
        $this->assertEquals([
            $player->uuid => $player->lqipUrl(),
            $background->uuid => $background->lqipUrl(),
        ], $result['lqip']);
        $this->assertStringContainsString('src="'.$player->lqipUrl().'"', $result['html']);
        $this->assertStringContainsString('data-mskba-lqip-full-src="'.$player->publicUrl().'"', $result['html']);
        $this->assertStringContainsString($background->lqipUrl(), $result['css']);
        $this->assertStringNotContainsString('url("'.$background->publicUrl().'")', $result['css']);
        $this->assertStringContainsString($background->publicUrl(), $result['originalCss']);
        $this->assertStringNotContainsString('data-site-media-lqip', $result['html']);
        $this->assertSame($htmlBefore, $page->fresh()->published_html);
        $this->assertSame($cssBefore, $page->fresh()->published_css);

        $this->withoutVite()->get('/lqip-check')->assertOk()
            ->assertSee('data-page-builder-lqip-manifest', false)
            ->assertSee($player->lqipUrl())
            ->assertSee($background->lqipUrl())
            ->assertSee('data-mskba-lqip-full-src', false)
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

    public function test_all_library_images_and_picture_sources_use_native_first_paint_without_moving_css_coordinates(): void
    {
        $uuidA = '12345678-1234-1234-1234-123456789abc';
        $uuidB = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $one = '/media/site/'.$uuidA;
        $two = '/media/site/'.$uuidB;
        $mini = [$uuidA => $one.'/lqip', $uuidB => $two.'/lqip'];
        $markup = '<div class="mskba-v2-venues__photo">'
            .'<img class="mskba-v2-venues__player" loading="lazy" src="'.$one.'">'
            .'<img class="mskba-v2-venues__hoop" src="'.$two.'">'
            .'<img class="no-mini" src="/images/ball-rolling.png">'
            .'</div><picture><source media="(max-width: 640px)" srcset="'.$two.'">'
            .'<img class="hero" data-site-media-lqip="'.$one.'/lqip" src="'.$one.'"></picture>';
        $generator = app(PageBuilderFirstPaintLqip::class);
        $rendered = $generator->apply($markup, $mini);

        $this->assertStringContainsString('src="'.$one.'/lqip"', $rendered);
        $this->assertStringContainsString('src="'.$two.'/lqip"', $rendered);
        $this->assertStringContainsString('srcset="'.$two.'/lqip"', $rendered);
        $this->assertStringContainsString('data-mskba-lqip-full-src="'.$one.'"', $rendered);
        $this->assertStringContainsString('data-mskba-lqip-full-srcset="'.$two.'"', $rendered);
        $this->assertStringContainsString('data-mskba-lqip-venue-player="1" loading="eager"', $rendered);
        $this->assertStringNotContainsString('data-site-media-lqip=', $rendered);
        $this->assertStringContainsString('src="/images/ball-rolling.png"', $rendered);
        $this->assertStringContainsString('<noscript>', $rendered);

        $css = '.player {background-size:contain;background-position:center;'
            .'background-image:linear-gradient(#000,#fff),url("'.$one.'");'
            .'transform:translate3d(1px,2px,0)}'
            .'.bg{background-image:url(\''.$two.'\')}';
        $lqCss = $generator->css($css, $mini);
        $this->assertStringContainsString('url("'.$one.'/lqip")', $lqCss);
        $this->assertStringContainsString('url("'.$two.'/lqip")', $lqCss);
        $this->assertStringContainsString('background-size:contain', $lqCss);
        $this->assertStringContainsString('transform:translate3d(1px,2px,0)', $lqCss);
        $this->assertSame($css, $generator->css($css, []));
    }

    public function test_unsupported_or_missing_preview_preserves_original_src(): void
    {
        $generator = app(PageBuilderFirstPaintLqip::class);
        $html = '<img class="player" src="/media/site/00000000-0000-4000-8000-000000000000">';
        $this->assertSame($html, $generator->apply($html, []));
        $this->assertSame($html, $generator->apply($html, ['00000000-0000-4000-8000-000000000000' => '/untrusted-preview']));
    }

    public function test_legacy_dark_theme_keeps_original_image_and_css_urls(): void
    {
        Storage::fake('public');
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('original.png', 300, 150));
        $page = PageBuilderPage::query()->create([
            'name' => 'Legacy theme', 'path' => '/legacy-image',
            'draft_html' => '<img src="'.$asset->publicUrl().'">',
            'draft_css' => '.demo{background:url("'.$asset->publicUrl().'")}',
        ]);
        app(PageBuilderPublisher::class)->publish($page);
        $legacy = config('themes');
        $legacy['active'] = 'mskba_dark';
        $this->app->instance(ThemeResolver::class, new ThemeResolver($legacy));

        $rendered = app(PageBuilderRenderer::class)->render($page->fresh());
        $this->assertStringContainsString('src="'.$asset->publicUrl().'"', $rendered['html']);
        $this->assertStringNotContainsString('data-mskba-lqip-full-src', $rendered['html']);
        $this->assertSame($rendered['originalCss'], $rendered['css']);
    }

}
