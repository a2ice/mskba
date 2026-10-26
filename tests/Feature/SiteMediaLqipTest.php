<?php

namespace Tests\Feature;

use App\Modules\Media\Application\Services\SiteMediaLqip;
use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Content\Application\Services\HeroSliderComponent;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SiteMediaLqipTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_builds_small_transparent_version_specific_preview_without_new_media_record(): void
    {
        Storage::fake('public');
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('player.png', 420, 860));
        $media = $asset->currentMedia;
        $path = SiteMediaLqip::path($media);

        Storage::disk('public')->assertExists($path);
        $this->assertSame(1, $asset->versions()->count());
        $this->assertLessThanOrEqual(10 * 1024, Storage::disk('public')->size($path));
        $this->assertSame('image/webp', getimagesizefromstring(Storage::disk('public')->get($path))['mime']);
        $this->assertSame('/media/site/'.$asset->uuid.'/lqip', $asset->lqipUrl());

        $response = $this->get($asset->lqipUrl())->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->withHeaders(['If-None-Match' => $etag])->get($asset->lqipUrl())->assertStatus(304);
    }

    public function test_no_preview_uses_original_and_missing_lqip_route_returns_404(): void
    {
        Storage::fake('public');
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('venue.jpg', 200, 100));
        Storage::disk('public')->delete(SiteMediaLqip::path($asset->currentMedia));

        $this->assertNull($asset->fresh()->lqipUrl());
        $this->get($asset->publicUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/media/site/'.$asset->uuid.'/lqip')->assertNotFound();

        $this->artisan('site-media:lqip')->assertExitCode(0);
        $this->assertNull($asset->fresh()->lqipUrl(), 'Dry run must not write.');
        $this->artisan('site-media:lqip', ['--apply' => true])->assertExitCode(0);
        $this->assertNotNull($asset->fresh()->lqipUrl());
    }

    public function test_replace_restore_and_delete_use_previews_for_matching_media_version(): void
    {
        Storage::fake('public');
        $manager = app(SiteMediaManager::class);
        $asset = $manager->upload(UploadedFile::fake()->image('one.png', 220, 110));
        $one = $asset->currentMedia;
        $pathOne = SiteMediaLqip::path($one);

        $asset = $manager->replace($asset, UploadedFile::fake()->image('two.jpg', 260, 130));
        $two = $asset->currentMedia;
        $pathTwo = SiteMediaLqip::path($two);
        Storage::disk('public')->assertExists([$pathOne, $pathTwo]);
        $asset = $manager->restore($asset);
        $this->assertSame($one->id, $asset->current_media_id);
        $this->assertNotNull($asset->lqipUrl());

        $asset = $manager->replace($asset, UploadedFile::fake()->image('three.png', 320, 160));
        Storage::disk('public')->assertMissing($pathTwo);
        $this->assertSame(2, $asset->versions()->count());

        $newestPath = SiteMediaLqip::path($asset->currentMedia);
        $manager->delete($asset);
        Storage::disk('public')->assertMissing([$pathOne, $newestPath]);
    }

    public function test_shared_blade_component_has_progressive_fallback_and_dimensions(): void
    {
        Storage::fake('public');
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('hero.png', 400, 200));
        $rendered = view('components.site-media-image', ['asset' => $asset, 'alt' => 'Мяч',
            'attributes' => new \Illuminate\View\ComponentAttributeBag])->render();

        $this->assertStringContainsString('data-site-media-progressive="1"', $rendered);
        $this->assertStringContainsString($asset->publicUrl(), $rendered);
        $this->assertStringContainsString($asset->lqipUrl(), $rendered);
        $this->assertStringContainsString('width="400"', $rendered);

        Storage::disk('public')->delete(SiteMediaLqip::path($asset->currentMedia));
        $renderedWithout = view('components.site-media-image', ['asset' => $asset->fresh(), 'alt' => 'Мяч',
            'attributes' => new \Illuminate\View\ComponentAttributeBag])->render();
        $this->assertStringNotContainsString('mskba-site-media-image__lqip', $renderedWithout);
        $this->assertStringContainsString($asset->publicUrl(), $renderedWithout);
    }
    public function test_hero_never_uses_legacy_preview_for_a_different_selected_asset(): void
    {
        Storage::fake('public');
        $manager = app(SiteMediaManager::class);
        $legacyFile = UploadedFile::fake()->image('old.png', 200, 100);
        $legacyBytes = file_get_contents($legacyFile->getRealPath());
        $legacy = $manager->importBytes($legacyBytes, 'Old', 'old.png', legacyPath: 'page-builder/hero/old.png');
        $selected = $manager->upload(UploadedFile::fake()->image('new.png', 320, 160));
        Storage::disk('public')->delete(SiteMediaLqip::path($selected->currentMedia));

        $slider = PageBuilderComponent::query()->where('slug', 'hero-slider')->firstOrFail();
        $slides = $slider->draft_slides;
        $slides[0]['layout'] = 'layers';
        $slides[0]['background_image_site_asset'] = $selected->uuid;
        $slides[0]['background_image'] = 'page-builder/hero/old.png';
        $slides[0]['elements'] = [[
            'type' => 'image',
            'name' => 'Игрок',
            'image_site_asset' => $selected->uuid,
            'image' => 'page-builder/hero/old.png',
        ]];
        $slider->update(['draft_slides' => [$slides[0]]]);
        $html = app(HeroSliderComponent::class)->render($slider->refresh(), true);

        $this->assertStringContainsString($selected->publicUrl(), $html);
        $this->assertStringNotContainsString($legacy->lqipUrl(), $html);
    }

}
