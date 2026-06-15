<?php

namespace Tests\Feature\Venue;

use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueShowPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_public_venue_show_page_returns_not_found_error_page(): void
    {
        $this
            ->get(route('venues.show', 'missing-venue'))
            ->assertNotFound()
            ->assertSee('Площадка не найдена');
    }

    public function test_public_venue_show_page_renders_location_and_placeholder_sections(): void
    {
        config(['integrations.yandex.api_key' => null]);

        $location = Location::factory()->create();
        $line = MetroLine::factory()->create([
            'name' => 'Серпуховско-Тимирязевская линия',
            'color' => '#9ca0a8',
        ]);
        $station = MetroStation::factory()->create([
            'metro_line_id' => $line->id,
            'name' => 'Верхние Лихоборы',
        ]);
        $location->metroStations()->attach($station->id);

        $venue = Venue::factory()->create([
            'location_id' => $location->id,
            'name' => 'Зал на Дубнинской',
            'alias' => 'na-dubninskoi',
            'type' => VenueTypeEnum::SPORTS_HALL,
            'status' => VenueStatusEnum::CONFIRMED,
            'description' => 'Баскетбольный зал для тренировок и игр.',
        ]);

        $this
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('Зал на Дубнинской')
            ->assertSee('Баскетбольный зал для тренировок и игр.')
            ->assertSee($location->address->full_address)
            ->assertSee('Верхние Лихоборы')
            ->assertSee('Серпуховско-Тимирязевская линия')
            ->assertSee('Опции')
            ->assertSee('Расписание')
            ->assertSee('Посты')
            ->assertSee('Отзывы')
            ->assertSee('Ключ Яндекс Карт не настроен.');
    }

    public function test_public_venue_show_page_renders_yandex_map_container_when_coordinates_and_api_key_exist(): void
    {
        config(['integrations.yandex.api_key' => 'test-yandex-key']);

        $location = Location::factory()->create();
        $venue = Venue::factory()->create([
            'location_id' => $location->id,
            'name' => 'Площадка с картой',
            'alias' => 'venue-with-map',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

        $this
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('data-venue-map', false)
            ->assertSee('data-yandex-map-api-key="test-yandex-key"', false)
            ->assertSee('data-latitude="' . $location->address->latitude . '"', false)
            ->assertSee('data-longitude="' . $location->address->longitude . '"', false)
            ->assertSee('data-venue-map-fallback', false)
            ->assertSee('hidden', false);
    }

    public function test_public_venue_show_page_renders_featured_media_gallery(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Площадка с фото',
            'alias' => 'venue-with-media',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);

        Media::factory()->for($venue, 'mediable')->create([
            'collection' => 'gallery',
            'disk' => 'public',
            'path' => 'venues/main-photo.jpg',
            'title' => 'Главное фото',
            'description' => 'Вид на игровую площадку',
            'is_featured' => true,
            'sort_order' => 10,
        ]);
        Media::factory()->for($venue, 'mediable')->create([
            'collection' => 'gallery',
            'disk' => 'public',
            'path' => 'venues/second-photo.jpg',
            'title' => 'Второе фото',
            'is_featured' => false,
            'sort_order' => 20,
        ]);

        $this
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('Галерея')
            ->assertSee('/storage/venues/main-photo.jpg', false)
            ->assertSee('/storage/venues/second-photo.jpg', false)
            ->assertSee('Главное фото')
            ->assertSee('Вид на игровую площадку')
            ->assertSee('2 фото');
    }

    public function test_public_venue_show_page_renders_active_amenities(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Площадка с опциями',
            'alias' => 'venue-with-amenities',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $activeAmenity = Amenity::factory()->create([
            'name' => 'Раздевалки',
            'alias' => 'locker-rooms',
            'description' => 'Есть отдельные мужские и женские раздевалки.',
            'icon' => 'ti-shirt',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $inactiveAmenity = Amenity::factory()->create([
            'name' => 'Скрытая опция',
            'alias' => 'hidden-amenity',
            'is_active' => false,
        ]);

        $venue->amenities()->attach($activeAmenity->id, [
            'note' => 'Доступны перед тренировкой и после игры.',
        ]);
        $venue->amenities()->attach($inactiveAmenity->id);

        $this
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('Раздевалки')
            ->assertSee('Доступны перед тренировкой и после игры.')
            ->assertSee('ti-shirt', false)
            ->assertSee('1 опций')
            ->assertDontSee('Скрытая опция');
    }
}
