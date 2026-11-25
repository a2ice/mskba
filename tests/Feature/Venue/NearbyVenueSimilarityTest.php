<?php

namespace Tests\Feature\Venue;

use App\Modules\Venue\Application\Services\VenueSearchCache;
use App\Modules\Venue\Application\UseCases\FindNearbyVenuesHandler;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class NearbyVenueSimilarityTest extends TestCase
{
    public function test_all_selected_conditions_must_match_the_same_zone(): void
    {
        $source = $this->venue(1, 55.75, [
            $this->court(11, 'free', 'outdoor', 'concrete', [1, 2], [28, 15], 2),
        ]);
        $split = $this->venue(2, 55.76, [
            $this->court(21, 'free', 'indoor', 'concrete', [1, 2], [28, 15], 2),
            $this->court(22, 'paid', 'outdoor', 'concrete', [1, 2], [28, 15], 2),
        ]);
        $match = $this->venue(3, 55.77, [
            $this->court(31, 'free', 'outdoor', 'concrete', [1, 2], [28, 15], 2),
        ]);

        $matches = $this->handler([$source, $split, $match])->similar(
            1, 55.75, 37.6, 11, ['type', 'payment', 'environment'],
        );

        $this->assertSame([3], array_column($matches, 'id'));
        $this->assertSame('/venues/3/courts/31', $matches[0]['url']);
        $this->assertSame(31, $matches[0]['court_id']);
    }

    public function test_surface_amenities_dimensions_and_hoops_are_checked_together(): void
    {
        $source = $this->venue(1, 55.75, [
            $this->court(11, 'free', 'outdoor', 'concrete', [1, 2], [28, 15], 2),
        ]);
        $wrongAmenities = $this->venue(2, 55.76, [
            $this->court(21, 'free', 'outdoor', 'concrete', [1], [28, 15], 2),
        ]);
        $wrongSize = $this->venue(3, 55.77, [
            $this->court(31, 'free', 'outdoor', 'concrete', [1, 2], [20, 10], 2),
        ]);
        $unknownSize = $this->venue(4, 55.78, [
            $this->court(41, 'free', 'outdoor', 'concrete', [1, 2], null, 2),
        ]);
        $match = $this->venue(5, 55.79, [
            $this->court(51, 'free', 'outdoor', 'concrete', [1, 2, 3], [28.5, 15], 2),
        ]);
        $criteria = ['payment', 'environment', 'surface', 'amenities', 'dimensions', 'hoops'];

        $matches = $this->handler([$source, $wrongAmenities, $wrongSize, $unknownSize, $match])
            ->similar(1, 55.75, 37.6, 11, $criteria);

        $this->assertSame([5], array_column($matches, 'id'));
    }

    public function test_when_none_within_ten_kilometres_only_three_nearest_matching_venues_are_returned(): void
    {
        $source = $this->venue(1, 55.75, [$this->court(11, 'free', 'outdoor')]);
        $candidates = [];
        foreach ([3 => 56.00, 4 => 56.10, 5 => 56.20, 6 => 56.30] as $id => $lat) {
            $candidates[] = $this->venue($id, $lat, [$this->court($id * 10, 'free', 'outdoor')]);
        }
        $result = $this->handler([$source, ...$candidates])
            ->similar(1, 55.75, 37.6, null, ['payment', 'environment']);

        $this->assertSame([3, 4, 5], array_column($result, 'id'));
        $this->assertTrue($result[0]['distance_meters'] > 10000);
    }

    private function handler(array $documents): FindNearbyVenuesHandler
    {
        // Seed the same cache consumed in production; VenueSearchCache is final.
        config()->set('venue_search.store', 'array');
        Cache::store('array')->put('venue-search:version', 1);
        Cache::store('array')->put('venue-search:index:court-features-v3:v1', $documents, 60);

        return new FindNearbyVenuesHandler(new VenueSearchCache());
    }

    private function court(int $id, string $payment, string $environment, ?string $surface = null,
        ?array $amenities = null, ?array $dimensions = null, ?int $hoops = null): array
    {
        return compact('id', 'payment', 'environment', 'surface', 'amenities', 'dimensions', 'hoops');
    }

    private function venue(int $id, float $latitude, array $courts): array
    {
        $access = ['free' => [], 'paid' => [], 'unknown' => []];
        $environments = [];
        $features = [];
        foreach ($courts as $court) {
            $courtId = $court['id'];
            $access[$court['payment']][] = [
                'court_id' => $courtId,
                'name' => 'Корт '.$courtId,
                'url' => '/venues/'.$id.'/courts/'.$courtId,
                'requires_booking' => false,
                'requires_booking_approval' => false,
            ];
            $environments[$courtId] = $court['environment'];
            $features[$courtId] = [
                'surface' => $court['surface'],
                'amenities' => $court['amenities'],
                'dimensions' => $court['dimensions'],
                'hoops' => $court['hoops'],
            ];
        }

        return [
            'id' => $id,
            'name' => 'Площадка '.$id,
            'latitude' => $latitude,
            'longitude' => 37.6,
            'type_slug' => 'school',
            'status_slug' => 'confirmed',
            'is_published' => true,
            'court_access' => $access,
            'court_environments' => $environments,
            'court_features' => $features,
        ];
    }
}
