<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Geography\CityResource;
use App\Filament\Backoffice\Resources\Geography\DistrictResource;
use App\Filament\Backoffice\Resources\Geography\MetroLineResource;
use App\Filament\Backoffice\Resources\Geography\MetroStationResource;
use App\Filament\Backoffice\Resources\Venues\VenueDuplicateResource;
use App\Filament\Backoffice\Resources\Venues\VenueModerationResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Location\Domain\Models\District;
use App\Modules\Location\Domain\Models\MetroLine;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Moderation\Domain\Enums\ModerationTypeEnum;
use App\Modules\Venue\Application\UseCases\BackofficeVenueModerationHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Database\Seeders\MoscowMetroSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackofficeVenueGeographyModerationTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
        $user->createProfile([]);

        return $user;
    }

    public function test_geography_and_metro_resources_are_visible_only_to_authorized_staff(): void
    {
        $super = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        $admin = $this->user(Role::ADMIN);

        $this->actingAs($admin);
        foreach ([CityResource::class, DistrictResource::class, MetroLineResource::class,
            MetroStationResource::class, VenueModerationResource::class, VenueDuplicateResource::class] as $resource) {
            $this->assertFalse($resource::canViewAny());
        }

        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_VENUE_MANAGE->value,
            Permission::BACKOFFICE_VENUE_MODERATE->value,
        ]);
        $this->flushSession();
        $this->actingAs($manager);

        foreach ([CityResource::class, DistrictResource::class, MetroLineResource::class,
            MetroStationResource::class, VenueModerationResource::class, VenueDuplicateResource::class] as $resource) {
            $this->assertTrue($resource::canViewAny());
            $this->get($resource::getUrl('index'))->assertOk();
        }
    }

    public function test_city_district_line_and_station_deletion_guards_respect_existing_links(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN));
        $city = City::query()->create(['name' => 'Тестоград', 'alias' => 'testograd']);
        $district = District::query()->create([
            'city_id' => $city->id, 'name' => 'Центр', 'alias' => 'center',
        ]);
        $line = MetroLine::query()->create(['name' => 'Тестовая линия']);
        $station = MetroStation::query()->create(['metro_line_id' => $line->id, 'name' => 'Тестовая']);

        $this->assertFalse(CityResource::canDelete($city));
        $this->assertFalse(MetroLineResource::canDelete($line));
        $this->assertTrue(DistrictResource::canDelete($district));
        $this->assertTrue(MetroStationResource::canDelete($station));
    }

    public function test_moscow_metro_can_be_imported_independently_and_is_idempotent(): void
    {
        $this->seed(MoscowMetroSeeder::class);
        $this->assertSame(21, MetroLine::query()->count());
        $this->assertSame(452, MetroStation::query()->count());
        $this->seed(MoscowMetroSeeder::class);
        $this->assertSame(21, MetroLine::query()->count());
        $this->assertSame(452, MetroStation::query()->count());
    }

    public function test_moderation_review_is_not_implicitly_granted_by_venue_editing(): void
    {
        $super = $this->user(Role::SUPERADMIN);
        $manager = $this->user(Role::MANAGER);
        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_VENUE_MANAGE->value,
        ]);
        $this->actingAs($manager);
        $this->assertTrue(CityResource::canViewAny());
        $this->assertFalse(VenueModerationResource::canViewAny());
        $this->assertFalse(VenueDuplicateResource::canViewAny());
    }

    public function test_reviewer_can_approve_real_pending_request_and_preserve_submitter(): void
    {
        $super = $this->user(Role::SUPERADMIN);
        $owner = $this->user(Role::USER);
        $submitter = app(CurrentActorResolver::class)->resolve($owner, null);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $submitter->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $request = $venue->moderationRequests()->create([
            'type' => ModerationTypeEnum::VENUE,
            'status' => ModerationRequestStatusEnum::PENDING,
            'submitted_by_actor_id' => $submitter->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($super);
        app(BackofficeVenueModerationHandler::class)->approve($request, $super);

        $this->assertSame(ModerationRequestStatusEnum::APPROVED, $request->refresh()->status);
        $this->assertSame($owner->id, $request->submittedByActor->user_id);
        $this->assertSame($super->id, $request->reviewed_by_user_id);
        $this->assertSame(VenueStatusEnum::CONFIRMED, $venue->refresh()->status);
    }
}
