<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Content\Application\Services\HeroSliderSettings;
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
        $this->assertSame(256, $asset->currentMedia->width);
        $this->assertSame(256, $asset->currentMedia->height);

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
        $file = UploadedFile::fake()->image('layer.png', 200, 100);
        $contents = file_get_contents($file->getRealPath());
        $manager = app(SiteMediaManager::class);
        $a = $manager->importBytes($contents, 'Layer', 'layer.png', legacyPath: '/images/layer.png');
        $b = $manager->importBytes($contents, 'Layer', 'layer.png', legacyPath: '/images/layer.png');
        $this->assertSame($a->id, $b->id);
        $this->assertSame($contents, Storage::disk('public')->get($a->currentMedia->path));
    }

    public function test_superadmin_can_open_filament_media_and_folder_pages(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $folder = SiteMediaFolder::query()->create(['name' => 'Hero', 'slug' => 'hero-slider']);
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('hero.png', 400, 200), $folder->id);

        $this->actingAs($admin)->get('/backoffice/site-media-assets')->assertOk();
        $this->get('/backoffice/site-media-assets/'.$asset->id.'/edit')->assertOk();
        $this->get('/backoffice/site-media-folders')->assertOk();
        $this->get('/backoffice/site-media-folders/'.$folder->id.'/edit')->assertOk();
    }

    public function test_hero_layers_accept_existing_library_images_without_legacy_uploads(): void
    {
        Storage::fake('public');
        $asset = app(SiteMediaManager::class)->upload(UploadedFile::fake()->image('hero.png', 400, 200));
        HeroSliderSettings::validate([[
            'layout' => 'layers',
            'elements' => [
                ['type' => 'image', 'name' => 'Фото', 'image_site_asset' => $asset->uuid],
                ['type' => 'links', 'name' => 'Ссылки', 'links' => [
                    ['label' => 'Площадки', 'url' => '/venues', 'icon' => 'custom',
                        'image_site_asset' => $asset->uuid],
                ]],
            ],
        ]], []);

        $this->assertTrue(true);
    }

    public function test_regular_user_cannot_open_media_backoffice(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::USER,
        ]);
        $this->actingAs($user)->get('/backoffice/site-media-assets')->assertForbidden();
    }

    public function test_missing_asset_returns_404(): void
    {
        $this->get('/media/site/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }
}
