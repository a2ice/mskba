<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\Services\VenueGalleryManager;
use App\Modules\Venue\Application\Services\VenuePhotoCropService;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class VenuePhotoCroppingTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_keeps_published_photo_and_apply_crops_from_the_original(): void
    {
        Storage::fake('public');
        $venue = Venue::factory()->create();
        $uploaded = UploadedFile::fake()->image('school.jpg', 1200, 600);
        $contents = file_get_contents($uploaded->getRealPath());
        $this->assertIsString($contents);

        $photo = app(VenueGalleryManager::class)->store($venue, $contents, forcePublished: true);
        $originalPath = $photo->original_path;
        $firstPublicPath = $photo->path;

        $this->assertNotNull($originalPath);
        $this->assertNotSame($originalPath, $firstPublicPath);
        Storage::disk('public')->assertExists([$originalPath, $firstPublicPath]);
        $this->assertSame(1200, getimagesizefromstring(Storage::disk('public')->get($originalPath))[0]);
        $this->assertSame(500, getimagesizefromstring(Storage::disk('public')->get($firstPublicPath))[0]);

        $crop = app(VenuePhotoCropService::class);
        $crop->saveDraft($venue, $photo->id, [
            'x' => 0.25, 'y' => 0, 'width' => 0.5, 'height' => 1,
        ]);

        $this->assertSame($firstPublicPath, $photo->refresh()->path);
        $this->assertSame(0.25, $photo->crop_draft['x']);

        $crop->apply($venue, $photo->id);
        $photo->refresh();

        $this->assertSame($originalPath, $photo->original_path);
        $this->assertNull($photo->crop_draft);
        $this->assertSame(0.5, $photo->crop_applied['width']);
        $this->assertNotSame($firstPublicPath, $photo->path);
        $this->assertSame([500, 500], array_slice(getimagesizefromstring(Storage::disk('public')->get($photo->path)), 0, 2));

        $crop->saveDraft($venue, $photo->id, [
            'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1,
        ]);
        $crop->apply($venue, $photo->id);
        $photo->refresh();

        $this->assertSame($originalPath, $photo->original_path);
        $this->assertSame([500, 250], array_slice(getimagesizefromstring(Storage::disk('public')->get($photo->path)), 0, 2));

        app(VenueGalleryManager::class)->delete($venue, $photo->id, forcePublished: true);
        Storage::disk('public')->assertMissing([$photo->path, $originalPath]);
    }

    public function test_photo_crop_is_scoped_to_its_venue(): void
    {
        Storage::fake('public');
        $venue = Venue::factory()->create();
        $anotherVenue = Venue::factory()->create();
        $uploaded = UploadedFile::fake()->image('court.png', 600, 400);
        $photo = app(VenueGalleryManager::class)->store(
            $venue,
            file_get_contents($uploaded->getRealPath()),
            forcePublished: true,
        );

        $this->expectException(ModelNotFoundException::class);

        app(VenuePhotoCropService::class)->saveDraft($anotherVenue, $photo->id, [
            'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1,
        ]);
    }

    public function test_invalid_crop_cannot_write_outside_original(): void
    {
        Storage::fake('public');
        $venue = Venue::factory()->create();
        $uploaded = UploadedFile::fake()->image('court.jpg', 600, 400);
        $photo = app(VenueGalleryManager::class)->store(
            $venue,
            file_get_contents($uploaded->getRealPath()),
            forcePublished: true,
        );

        $this->expectException(ValidationException::class);

        app(VenuePhotoCropService::class)->saveDraft($venue, $photo->id, [
            'x' => 0.75, 'y' => 0, 'width' => 0.5, 'height' => 1,
        ]);
    }
}
