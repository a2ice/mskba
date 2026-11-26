<?php

namespace Tests\Feature\Content;

use App\Modules\Content\Application\Services\VenueCatalogLandingSelectionAudit;
use App\Modules\Content\Domain\Models\VenueCatalogLanding;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class VenueCatalogLandingSelectionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_counts_only_public_matches_and_missing_districts_in_city_baseline(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => true);
        $city = City::factory()->create();
        $otherCity = City::factory()->create();
        $district = District::factory()->create(['city_id' => $city->id, 'name' => 'A district']);
        $emptyDistrict = District::factory()->create(['city_id' => $city->id, 'name' => 'B district']);
        $otherDistrict = District::factory()->create(['city_id' => $otherCity->id]);

        $this->venue($city, $district);
        $this->venue($city, null);
        $this->venue($city, $otherDistrict);
        $this->venue($city, null, false);
        $this->venue($otherCity, $otherDistrict);

        $landing = VenueCatalogLanding::query()->create([
            'name' => 'Audit', 'h1' => 'Audit', 'slug' => 'audit/city',
            'city_id' => $city->id, 'district_id' => $district->id,
        ]);

        $audit = app(VenueCatalogLandingSelectionAudit::class);
        $this->assertSame([
            'matching' => 1, 'city_matching' => 3, 'missing_district' => 2, 'district_count' => 2,
            'districts' => [
                ['id' => (int) $district->id, 'name' => 'A district', 'matching' => 1],
                ['id' => (int) $emptyDistrict->id, 'name' => 'B district', 'matching' => 0],
            ],
        ], $audit->inspect($landing));
        $this->assertFalse($landing->fresh()->is_active);
        $this->assertSame($district->id, $landing->fresh()->district_id);

        $landing->update(['district_id' => null]);
        $this->assertSame([
            'matching' => 3, 'city_matching' => 3, 'missing_district' => 2, 'district_count' => 2,
            'districts' => [
                ['id' => (int) $district->id, 'name' => 'A district', 'matching' => 1],
                ['id' => (int) $emptyDistrict->id, 'name' => 'B district', 'matching' => 0],
            ],
        ], $audit->inspect($landing));
    }

    public function test_audit_requires_content_permission(): void
    {
        $this->actingAs(User::factory()->create());
        Gate::define('manage-content', fn (User $user): bool => false);
        $this->expectException(AuthorizationException::class);
        app(VenueCatalogLandingSelectionAudit::class)->inspect(new VenueCatalogLanding);
    }

    private function venue(City $city, ?District $district, bool $published = true): void
    {
        $address = Address::factory()->create([
            'city_id' => $city->id, 'district_id' => $district?->id,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        Venue::factory()->create([
            'location_id' => $location->id,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'is_published' => $published,
        ]);
    }
}
