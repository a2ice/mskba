<?php

namespace Tests\Feature\Venue;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VenueCourtPublicContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_primary_court_has_compact_picker_and_own_characteristics(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Кампус на Яхромской',
            'alias' => 'kampus-na-yakhromskoy',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $primary = $venue->primaryCourt()->firstOrFail();
        $primary->update([
            'name' => 'Универсальный зал 1',
            'hoops_count' => 2,
            'supports_halves' => true,
            'surface_type' => VenueSurfaceTypeEnum::PARQUET,
        ]);
        $second = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Малый зал с очень длинным названием',
            'alias' => 'malyi-zal',
            'sort_order' => 20,
            'is_primary' => false,
            'hoops_count' => 1,
            'surface_type' => VenueSurfaceTypeEnum::RUBBER,
            'supports_halves' => false,
            'allows_whole' => true,
            'allows_halves' => false,
        ]);

        $response = $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $second->routeIdentifier()]))
            ->assertOk()
            ->assertSee('Количество залов')
            ->assertSee('Количество колец')
            ->assertSee('Резиновое покрытие')
            ->assertSee('2 зала')
            ->assertSee('Выбрать зал')
            ->assertSee('data-venue-court-picker-trigger', false)
            ->assertSee('data-modal="venue-court-picker-'.$venue->id.'"', false)
            ->assertSee('ti-layout-grid', false)
            ->assertDontSee('data-venue-court-selector', false)
            ->assertSee('Универсальный зал 1')
            ->assertSee('Малый зал с очень длинным названием')
            ->assertSee(route('venues.show', $venue->routeIdentifier()), false)
            ->assertSee(route('venues.courts.show', [$venue->routeIdentifier(), $second->routeIdentifier()]), false);

        $response->assertSee('Основной');
    }

    public function test_selected_court_photos_override_venue_gallery_and_empty_court_falls_back_to_venue_gallery(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $primary = $venue->primaryCourt()->firstOrFail();
        $parentPhoto = $venue->media()->create([
            'collection' => 'gallery',
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'venues/parent.webp',
            'mime' => 'image/webp',
            'size' => 100,
            'is_featured' => true,
            'sort_order' => 0,
        ]);

        $this->get(route('venues.show', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee($parentPhoto->publicUrl(), false);

        $second = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Зал 2',
            'alias' => 'zal-2',
            'sort_order' => 20,
            'is_primary' => false,
            'hoops_count' => 2,
            'supports_halves' => true,
            'allows_whole' => true,
            'allows_halves' => true,
        ]);

        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $second->routeIdentifier()]))
            ->assertOk()
            ->assertSee($parentPhoto->publicUrl(), false);

        $courtPhoto = $second->media()->create([
            'collection' => 'gallery',
            'source' => 'upload',
            'disk' => 'public',
            'path' => 'venues/'.$venue->id.'/courts/'.$second->id.'/court.webp',
            'mime' => 'image/webp',
            'size' => 100,
            'is_featured' => true,
            'sort_order' => 0,
        ]);

        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $second->routeIdentifier()]))
            ->assertOk()
            ->assertSee($courtPhoto->publicUrl(), false)
            ->assertDontSee($parentPhoto->publicUrl(), false);

        $this->assertNotNull($primary);
    }

    public function test_primary_court_nested_url_redirects_to_canonical_venue_url(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $primary = $venue->primaryCourt()->firstOrFail();

        $this->get(route('venues.courts.show', [$venue->routeIdentifier(), $primary->routeIdentifier()]))
            ->assertRedirect(route('venues.show', $venue->routeIdentifier()))
            ->assertStatus(301);
    }

    public function test_activity_endpoint_is_scoped_to_selected_court_and_keeps_legacy_public_events(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $primary = $venue->primaryCourt()->firstOrFail();
        $second = VenueCourt::query()->create([
            'venue_id' => $venue->id,
            'name' => 'Зал 2',
            'alias' => 'zal-2',
            'sort_order' => 20,
            'is_primary' => false,
            'hoops_count' => 2,
            'supports_halves' => true,
            'allows_whole' => true,
            'allows_halves' => true,
        ]);

        $selectedEvent = $this->futureEvent($venue, 'Игра во втором зале', $second->id);
        $otherCourtEvent = $this->futureEvent($venue, 'Игра в основном зале', $primary->id);
        $legacyEvent = $this->futureEvent($venue, 'Старое мероприятие без зала', $primary->id);
        Event::query()->whereKey($legacyEvent->id)->update(['venue_court_id' => null]);
        $privateEvent = $this->futureEvent($venue, 'Закрытая тренировка', $second->id);
        $privateEvent->update(['visibility' => EventVisibilityEnum::PRIVATE]);

        $response = $this->getJson(route('venues.activities', [
            'venue' => $venue->routeIdentifier(),
            'court' => $second->routeIdentifier(),
        ]))->assertOk();

        $response
            ->assertJsonPath('venue_court_id', $second->id)
            ->assertJsonPath('venue_court_name', 'Зал 2')
            ->assertJsonFragment(['title' => $selectedEvent->title])
            ->assertJsonFragment(['title' => $legacyEvent->title])
            ->assertJsonMissing(['title' => $otherCourtEvent->title])
            ->assertJsonMissing(['title' => $privateEvent->title]);
    }

    private function futureEvent(Venue $venue, string $title, int $courtId): Event
    {
        return Event::factory()->create([
            'venue_id' => $venue->id,
            'venue_court_id' => $courtId,
            'title' => $title,
            'type' => EventTypeEnum::TRAINING,
            'status' => EventStatusEnum::PUBLISHED,
            'visibility' => EventVisibilityEnum::PUBLIC,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
    }
}
