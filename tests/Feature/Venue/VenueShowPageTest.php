<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueReview;
use App\Modules\Venue\Domain\Models\VenueSchedule;
use App\Modules\Venue\Domain\Models\VenueScheduleInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            ->assertSee('data-latitude="'.$location->address->latitude.'"', false)
            ->assertSee('data-longitude="'.$location->address->longitude.'"', false)
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
            ->assertSee('data-venue-gallery-item', false)
            ->assertSee('data-venue-gallery-modal', false)
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

    public function test_public_venue_show_page_renders_schedule_intervals(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        try {
            $venue = Venue::factory()->create([
                'name' => 'Площадка с расписанием',
                'alias' => 'venue-with-schedule',
                'status' => VenueStatusEnum::CONFIRMED,
            ]);
            $schedule = VenueSchedule::factory()->for($venue)->create([
                'timezone' => 'Europe/Moscow',
            ]);

            VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
                'day_of_week' => 1,
                'starts_at' => '10:00',
                'ends_at' => '12:30',
                'sort_order' => 10,
            ]);
            VenueScheduleInterval::factory()->for($schedule, 'schedule')->create([
                'day_of_week' => 3,
                'starts_at' => '18:00',
                'ends_at' => '21:00',
                'sort_order' => 10,
            ]);

            $this
                ->get(route('venues.show', $venue->alias))
                ->assertOk()
                ->assertSee('Расписание')
                ->assertSee('14 дней')
                ->assertSee('Выбрать время')
                ->assertSee('Нажмите на день, чтобы посмотреть интервалы.')
                ->assertSee('data-venue-day-card', false)
                ->assertSee('data-venue-day-modal', false)
                ->assertSee('15 июн')
                ->assertSee('Пн')
                ->assertSee('Ср')
                ->assertSee('10:00-12:30')
                ->assertSee('18:00-21:00')
                ->assertSee('Закрыто')
                ->assertDontSee('Jun')
                ->assertDontSee('Mon')
                ->assertDontSee('Wed');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_public_venue_show_page_renders_published_reviews_and_rating(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Площадка с отзывами',
            'alias' => 'venue-with-reviews',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $author = User::factory()->create([
            'username' => 'review_author',
        ]);
        Profile::factory()->for($author)->create([
            'first_name' => 'Иван',
            'last_name' => 'Петров',
        ]);

        VenueReview::factory()->for($venue)->for($author)->create([
            'rating' => 5,
            'body' => 'Хороший паркет и удобные раздевалки.',
            'is_published' => true,
            'published_at' => '2026-06-14 12:00:00',
        ]);
        VenueReview::factory()->for($venue)->create([
            'rating' => 3,
            'body' => 'Нормальный зал для тренировок.',
            'is_published' => true,
            'published_at' => '2026-06-13 12:00:00',
        ]);
        VenueReview::factory()->for($venue)->create([
            'rating' => 1,
            'body' => 'Этот отзыв еще не опубликован.',
            'is_published' => false,
            'published_at' => null,
        ]);

        $this
            ->get(route('venues.show', $venue->alias))
            ->assertOk()
            ->assertSee('Отзывы')
            ->assertSee('4,0')
            ->assertSee('2 оценок')
            ->assertSee('Иван Петров')
            ->assertSee('14 июн 2026')
            ->assertSee('Хороший паркет и удобные раздевалки.')
            ->assertSee('Нормальный зал для тренировок.')
            ->assertDontSee('Этот отзыв еще не опубликован.')
            ->assertDontSee('14 Jun 2026');
    }
}
