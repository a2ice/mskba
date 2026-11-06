<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueRepresentativePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_home_map_fall_back_to_first_available_court_gallery_photo(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $first = $venue->primaryCourt()->firstOrFail();
        $other = $venue->courts()->create([
            'name' => 'Уличный корт',
            'alias' => 'outdoor-court',
            'sort_order' => 20,
            'environment' => 'outdoor',
        ]);
        $fallback = $this->makePhoto($other, 'gallery/second-court.webp');

        $this->assertSame($fallback->id, $venue->fresh()->representativePhoto()?->id);
        $this->assertNull($first->media()->first());

        $list = app(ListVenuesHandler::class)->handle(null);
        $item = collect($list)->first(fn ($item): bool => $item->id === $venue->id);
        $this->assertNotNull($item);
        $this->assertSame($fallback->publicUrl(), $item->imageUrl);

        $map = $this->getJson(route('api.v1.home.venues-map'))->assertOk();
        $data = collect($map->json('data'))->firstWhere('id', $venue->id);
        $this->assertSame(url($fallback->publicUrl()), $data['image_url']);
    }

    public function test_venue_gallery_photo_always_takes_precedence_over_court_photo(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $court = $venue->primaryCourt()->firstOrFail();
        $this->makePhoto($court, 'gallery/court-featured.webp', featured: true);
        $own = $this->makePhoto($venue, 'gallery/venue.webp');

        $this->assertSame($own->id, $venue->fresh()->representativePhoto()?->id);
        $item = collect(app(ListVenuesHandler::class)->handle(null))
            ->first(fn ($item): bool => $item->id === $venue->id);
        $this->assertSame($own->publicUrl(), $item->imageUrl);

        $map = $this->getJson(route('api.v1.home.venues-map'))->assertOk();
        $data = collect($map->json('data'))->firstWhere('id', $venue->id);
        $this->assertSame(url($own->publicUrl()), $data['image_url']);
    }

    public function test_non_gallery_venue_media_does_not_hide_court_gallery_fallback(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $court = $venue->primaryCourt()->firstOrFail();
        $venue->media()->create([
            'collection' => 'banner', 'source' => 'upload', 'disk' => 'public',
            'path' => 'banner/venue.webp', 'mime' => 'image/webp', 'size' => 100,
            'is_featured' => true, 'sort_order' => 0,
        ]);
        $fallback = $this->makePhoto($court, 'gallery/real-court.webp');
        $this->assertSame($fallback->id, $venue->fresh()->representativePhoto()?->id);
    }

    private function makePhoto(Venue|VenueCourt $owner, string $path, bool $featured = false): Media
    {
        return $owner->media()->create([
            'collection' => 'gallery',
            'source' => 'upload',
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/webp',
            'size' => 100,
            'sort_order' => 0,
            'is_featured' => $featured,
        ]);
    }
}
