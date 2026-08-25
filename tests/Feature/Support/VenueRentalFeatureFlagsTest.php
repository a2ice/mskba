<?php

namespace Tests\Feature\Support;

use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class VenueRentalFeatureFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (VenueRentalFeature::cases() as $feature) {
            Route::get("/_test/venue-rental-features/{$feature->value}", static fn () => ['enabled' => true])
                ->middleware("venue-rental-feature:{$feature->value}");
        }
    }

    public function test_all_venue_rental_features_are_disabled_by_default(): void
    {
        $features = app(FeatureFlags::class);

        foreach (VenueRentalFeature::cases() as $feature) {
            $this->assertFalse($features->enabled($feature), $feature->value);

            $this->getJson("/_test/venue-rental-features/{$feature->value}")
                ->assertNotFound()
                ->assertExactJson([
                    'message' => 'feature_disabled',
                    'code' => 'feature_disabled',
                ]);
        }
    }

    public function test_each_venue_rental_feature_can_be_enabled_independently(): void
    {
        foreach (VenueRentalFeature::cases() as $enabledFeature) {
            foreach (VenueRentalFeature::cases() as $feature) {
                config()->set("features.venue_rental.{$feature->value}", $feature === $enabledFeature);
            }

            foreach (VenueRentalFeature::cases() as $feature) {
                $response = $this->getJson("/_test/venue-rental-features/{$feature->value}");

                if ($feature === $enabledFeature) {
                    $response->assertOk()->assertExactJson(['enabled' => true]);
                } else {
                    $response->assertNotFound()->assertJsonPath('code', 'feature_disabled');
                }
            }
        }
    }

    public function test_disabled_web_route_uses_an_ordinary_not_found_response(): void
    {
        $this->get('/_test/venue-rental-features/rental_flow')
            ->assertNotFound()
            ->assertDontSee('feature_disabled');
    }
}
