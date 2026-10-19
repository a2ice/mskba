<?php

namespace Tests\Feature;

use App\Modules\Media\Application\Services\SiteMediaManager;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SiteMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stable_public_url_versions_and_restore(): void
    {
        Storage::fake('public');
        $manager = app(SiteMediaManager::class);
        $folder = SiteMediaFolder::query()->create(['name' => 'Hero', 'slug' => 'hero-slider']);

        $asset = $manager->upload(UploadedFile::fake()->image('ball.png', 256, 256), $folder->id);
        $url = $asset->publicUrl();
        $first = $asset->currentMedia->path;

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(1, $asset->versions()->count());

        $asset = $manager->replace($asset, UploadedFile::fake()->image('ball2.jpg', 512, 256));
        $second = $asset->currentMedia->path;
        $this->assertSame($url, $asset->publicUrl());
        $this->assertSame($first, $asset->previousMedia->path);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $asset = $manager->replace($asset, UploadedFile::fake()->image('ball3.png', 200, 200));
        $this->assertSame(2, $asset->versions()->count());
        $this->assertSame($second, $asset->previousMedia->path);
        Storage::disk('public')->assertMissing($first);

        $asset = $manager->restore($asset);
        $this->assertSame($second, $asset->currentMedia->path);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $folder->update(['name' => 'Hero Renamed', 'slug' => 'hero-renamed']);
        $this->assertSame($url, $asset->fresh()->publicUrl());
    }

    public function test_import_is_idempotent_and_does_not_change_file_bytes(): void
    {
        Storage::fake('public');
        $contents = file_get_contents(UploadedFile::fake()->image('layer.png', 200, 100)->getRealPath());
        $manager = app(SiteMediaManager::class);
        $a = $manager->importBytes($contents, 'Layer', 'layer.png', legacyPath: '/images/layer.png');
        $b = $manager->importBytes($contents, 'Layer', 'layer.png', legacyPath: '/images/layer.png');
        $this->assertSame($a->id, $b->id);
        $this->assertSame($contents, Storage::disk('public')->get($a->currentMedia->path));
    }

    public function test_missing_asset_returns_404(): void
    {
        $this->get('/media/site/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }
}
