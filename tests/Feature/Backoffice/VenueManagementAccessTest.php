<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\AmenityResource;
use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VenueManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(UserSystemRoleEnum $role, UserStatusEnum $status = UserStatusEnum::CONFIRMED): User
    {
        $user = User::factory()->create(['system_role' => $role, 'status' => $status]);
        $user->createProfile([]);

        return $user;
    }

    public function test_venue_resource_is_superadmin_only_by_default_but_can_be_delegated(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $manager = $this->account(UserSystemRoleEnum::MANAGER);

        $this->actingAs($admin);
        $this->assertFalse(VenueResource::canViewAny());
        $this->assertFalse(AmenityResource::canViewAny());
        $this->get('/backoffice/venues')->assertForbidden();

        $this->flushSession();
        $this->actingAs($super);
        $this->assertTrue(VenueResource::canViewAny());
        $this->get('/backoffice/venues')->assertOk();

        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_VENUE_MANAGE->value,
        ]);
        $this->flushSession();
        $this->actingAs($manager);
        $this->assertTrue(VenueResource::canViewAny());
        $this->assertTrue(AmenityResource::canViewAny());
        $this->get('/backoffice/venues')->assertOk();
        $this->get(AmenityResource::getUrl('index'))->assertOk();
        $this->get('/backoffice/design-themes')->assertForbidden();
    }

    public function test_venue_editor_requires_explicit_backoffice_permission(): void
    {
        $admin = $this->account(UserSystemRoleEnum::ADMIN);
        $this->actingAs($admin);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(VenueBackofficeEditor::class)->save([
            'name' => 'Недоступная площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
        ]);
    }

    public function test_authorized_editor_creates_full_venue_and_soft_delete_keeps_related_data(): void
    {
        Queue::fake();
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);

        $venue = app(VenueBackofficeEditor::class)->save([
            'name' => 'Тестовая площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'operational_status' => 'active',
            'access_type' => 'free',
            'raw_address' => 'Москва, Примерная улица, 1',
            'location_city' => 'Москва',
            'location_street' => 'Примерная улица',
            'location_building' => '1',
            'location_latitude' => 55.75,
            'location_longitude' => 37.62,
            'characteristics' => ['hoops_count' => 2],
            'weekly_intervals' => [['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '21:00']],
            'tags_text' => 'Стритбол, 3х3',
        ]);

        $this->get('/backoffice/venues/create')->assertOk();
        $this->get('/backoffice/venues/'.$venue->id.'/edit')->assertOk();

        $this->assertSame('Тестовая площадка', $venue->name);
        $this->assertFalse($venue->requires_payment);
        $this->assertNotNull($venue->location?->address);
        $this->assertSame(2, (int) $venue->characteristics?->hoops_count);
        $this->assertSame(1, $venue->schedule?->intervals()->count());
        $this->assertSame(2, $venue->tags()->count());
        $this->assertSame(1, $venue->courts()->count());
        $this->assertTrue($venue->primaryCourt()->exists());

        $venue->delete();
        $this->assertSame(1, Venue::onlyTrashed()->whereKey($venue->id)->count());
        $this->assertSame(1, $venue->courts()->count());
    }
}
