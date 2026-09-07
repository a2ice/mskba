<?php

namespace Tests\Feature\Portal;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeEventDiscoveryRadiusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_radius_filters_events_before_requested_result_limit(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00:00', 'Europe/Moscow'));

        $farVenue = $this->venueAt('Far Hall', 55.95, 37.80);
        foreach (range(1, 31) as $index) {
            Event::factory()->create([
                'venue_id' => $farVenue->id,
                'title' => sprintf('Far event %02d', $index),
                'type' => EventTypeEnum::TRAINING->value,
                'status' => EventStatusEnum::PUBLISHED->value,
                'visibility' => EventVisibilityEnum::PUBLIC->value,
                'starts_at' => CarbonImmutable::parse('2026-09-08 10:00:00', 'Europe/Moscow')->addMinutes($index),
                'ends_at' => CarbonImmutable::parse('2026-09-08 12:00:00', 'Europe/Moscow')->addMinutes($index),
            ]);
        }

        $nearVenue = $this->venueAt('Near Hall', 55.7520, 37.6200);
        Event::factory()->create([
            'venue_id' => $nearVenue->id,
            'title' => 'Nearby event',
            'type' => EventTypeEnum::TRAINING->value,
            'status' => EventStatusEnum::PUBLISHED->value,
            'visibility' => EventVisibilityEnum::PUBLIC->value,
            'starts_at' => CarbonImmutable::parse('2026-09-09 18:00:00', 'Europe/Moscow'),
            'ends_at' => CarbonImmutable::parse('2026-09-09 20:00:00', 'Europe/Moscow'),
        ]);

        $this->getJson(route('home.event-discovery', [
            'type' => EventTypeEnum::TRAINING->value,
            'latitude' => 55.751244,
            'longitude' => 37.618423,
            'radius_km' => 3,
            'date_from' => '2026-09-07',
            'date_to' => '2026-09-14',
            'limit' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.title', 'Nearby event');
    }

    private function venueAt(string $name, float $latitude, float $longitude): Venue
    {
        $address = Address::factory()->create([
            'city' => 'Москва',
            'street' => 'Тестовая улица',
            'building' => '1',
            'full_address' => 'Москва, Тестовая улица, 1',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        return Venue::factory()->create([
            'name' => $name,
            'location_id' => $location->id,
            'raw_address' => $address->full_address,
        ]);
    }
}
