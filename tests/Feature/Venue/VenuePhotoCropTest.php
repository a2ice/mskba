<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\Services\VenueGalleryManager;
use App\Modules\Venue\Application\Services\VenuePhotoCropService;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenuePhotoCropTest extends TestCase
{
    use RefreshDatabase;

    public function test_preserves_original_and_autosaved_draft_is_not_published_until_applied(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $venue = Venue::factory()->create();
        $raw = $this->stripedPng();

        $photo = app(VenueGalleryManager::class)->store($venue, $raw, forcePublished: true);
        $initialPath = $photo->path;

        $this->assertNotNull($photo->original_path);
        $this->assertSame('local', $photo->original_disk);
        Storage::disk('public')->assertExists($initialPath);
        Storage::disk('local')->assertExists($photo->original_path);
        $this->assertSame($raw, Storage::disk('local')->get($photo->original_path));

        $cropper = app(VenuePhotoCropService::class);
        $rightHalf = ['x' => 0.5, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0];

        $cropper->saveDraft($venue, $photo->id, $rightHalf);
        $photo->refresh();
        $this->assertSame($initialPath, $photo->path, 'An unapproved crop must not change the public image.');
        $this->assertEquals($rightHalf, $photo->crop_draft);

        $cropper->apply($venue, $photo->id);
        $photo->refresh();
        $this->assertNull($photo->crop_draft);
        $this->assertEquals($rightHalf, $photo->crop_applied);
        $this->assertNotSame($initialPath, $photo->path);
        $this->assertSame($raw, Storage::disk('local')->get($photo->original_path));
        Storage::disk('public')->assertMissing($initialPath);
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('local')->assertExists($photo->original_path);

        $image = imagecreatefromstring(Storage::disk('public')->get($photo->path));
        $this->assertNotFalse($image);
        $this->assertSame(200, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $pixel = imagecolorsforindex($image, imagecolorat($image, 100, 100));
        $this->assertGreaterThan($pixel['red'] + 80, $pixel['blue']);
        imagedestroy($image);

        $leftHalf = ['x' => 0.0, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0];
        $cropper->saveDraft($venue, $photo->id, $leftHalf);
        $cropper->apply($venue, $photo->id);

        $image = imagecreatefromstring(Storage::disk('public')->get($photo->refresh()->path));
        $this->assertNotFalse($image);
        $pixel = imagecolorsforindex($image, imagecolorat($image, 100, 100));
        $this->assertGreaterThan($pixel['blue'] + 80, $pixel['red'],
            'A second crop must use the original, not the preceding crop.');
        imagedestroy($image);
    }

    public function test_rejects_invalid_crop_and_photo_from_another_venue(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $venue = Venue::factory()->create();
        $otherVenue = Venue::factory()->create();
        $photo = app(VenueGalleryManager::class)->store($venue, $this->stripedPng(), forcePublished: true);
        $cropper = app(VenuePhotoCropService::class);

        try {
            $cropper->saveDraft($venue, $photo->id, ['x' => 0.8, 'y' => 0, 'width' => 0.5, 'height' => 1]);
            $this->fail('Out-of-bounds crop should be rejected.');
        } catch (ValidationException) {
            $this->assertNull($photo->refresh()->crop_draft);
        }

        $this->expectException(ModelNotFoundException::class);
        $cropper->saveDraft($otherVenue, $photo->id, ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]);
    }

    public function test_existing_normalized_photos_can_be_cropped_without_losing_the_original(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $venue = Venue::factory()->create();
        $original = app(VenueGalleryManager::class)->store($venue, $this->stripedPng(), forcePublished: true);
        $path = 'venues/'.$venue->id.'/older-image.webp';
        Storage::disk('public')->put($path, Storage::disk('public')->get($original->path));
        $legacy = $venue->media()->create([
            'collection' => 'gallery', 'source' => 'upload', 'disk' => 'public',
            'path' => $path, 'mime' => 'image/webp', 'size' => 200,
        ]);

        $cropper = app(VenuePhotoCropService::class);
        $cropper->saveDraft($venue, $legacy->id, ['x' => 0.5, 'y' => 0, 'width' => 0.5, 'height' => 1]);
        $cropper->apply($venue, $legacy->id);

        $this->assertSame($path, $legacy->refresh()->original_path);
        Storage::disk('public')->assertExists([$path, $legacy->path]);
    }

    private function stripedPng(): string
    {
        $image = imagecreatetruecolor(400, 200);
        imagefilledrectangle($image, 0, 0, 199, 199, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 200, 0, 399, 199, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
