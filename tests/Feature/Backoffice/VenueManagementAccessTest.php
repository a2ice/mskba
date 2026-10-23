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
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\UseCases\BackofficeVenueModerationHandler;
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

    public function test_superadmin_can_confirm_through_real_moderation_in_one_save(): void
    {
        Queue::fake();
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);

        $venue = app(VenueBackofficeEditor::class)->save([
            'name' => 'Подтверждаемая площадка',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'raw_address' => 'Москва, другая улица, 1',
            'location_city' => 'Москва',
            'location_street' => 'Другая улица',
            'location_building' => '1',
            'location_latitude' => 55.74,
            'location_longitude' => 37.60,
        ]);

        $this->assertSame(VenueStatusEnum::CONFIRMED, $venue->status);
        $this->assertNotNull($venue->created_by_actor_id);
        $request = $venue->moderationRequests()->firstOrFail();
        $this->assertSame(ModerationRequestStatusEnum::APPROVED, $request->status);
        $this->assertSame($super->id, $request->reviewed_by_user_id);
        $this->assertSame(
            app(CurrentActorResolver::class)->resolve($super, null)->id,
            $request->submitted_by_actor_id,
        );
        $this->assertCount(2, $request->messages);
    }

    public function test_quick_confirmation_reviews_existing_pending_request_without_replacing_submitter(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $owner = $this->account(UserSystemRoleEnum::USER);
        $actor = app(CurrentActorResolver::class)->resolve($owner, null);
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $actor->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $request = $venue->moderationRequests()->create([
            'type' => \App\Modules\Moderation\Domain\Enums\ModerationTypeEnum::VENUE,
            'submitted_by_actor_id' => $actor->id,
            'status' => ModerationRequestStatusEnum::PENDING,
            'submitted_at' => now(),
        ]);

        $this->actingAs($super);
        app(BackofficeVenueModerationHandler::class)->approveImmediately($venue, $super);

        $this->assertSame(1, $venue->moderationRequests()->count());
        $this->assertSame($actor->id, $request->refresh()->submitted_by_actor_id);
        $this->assertSame(ModerationRequestStatusEnum::APPROVED, $request->status);
        $this->assertSame(VenueStatusEnum::CONFIRMED, $venue->refresh()->status);
    }

    public function test_delegated_manager_cannot_use_superadmin_quick_confirmation(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $manager = $this->account(UserSystemRoleEnum::MANAGER);
        app(BackofficePermissionManager::class)->sync($super, $manager, [
            Permission::BACKOFFICE_VENUE_MANAGE->value,
            Permission::BACKOFFICE_VENUE_MODERATE->value,
        ]);
        $this->actingAs($manager);

        $this->expectException(ValidationException::class);
        app(VenueBackofficeEditor::class)->save([
            'name' => 'Площадка менеджера',
            'type' => VenueTypeEnum::STREET_COURT->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]);
    }

    public function test_existing_venue_without_coordinates_shows_field_errors_instead_of_crashing_on_confirmation(): void
    {
        $super = $this->account(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);

        $address = Address::factory()->create(['latitude' => null, 'longitude' => null]);
        $location = Location::factory()->create(['address_id' => $address->id]);
        $venue = Venue::factory()->create([
            'name' => 'Тушинская тестовая',
            'location_id' => $location->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $editor = app(VenueBackofficeEditor::class);
        $data = array_replace([
            'name' => $venue->name,
            'alias' => $venue->alias,
            'type' => $venue->type->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => $venue->operational_status->value,
            'raw_address' => $address->full_address,
        ], $editor->fields($venue));

        $data['status'] = VenueStatusEnum::CONFIRMED->value;

        try {
            $editor->save($data, $venue);
            $this->fail('Confirmation without coordinates must be rejected as a field validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.location_latitude', $exception->errors());
            $this->assertArrayHasKey('data.location_longitude', $exception->errors());
        }

        $this->assertSame(VenueStatusEnum::UNCONFIRMED, $venue->refresh()->status);
        $this->assertSame(0, $venue->moderationRequests()->count());
    }

    public function test_existing_venue_can_be_confirmed_after_coordinates_are_added(): void
    {
        Queue::fake();
        $this->actingAs($this->account(UserSystemRoleEnum::SUPERADMIN));

        $address = Address::factory()->create(['latitude' => null, 'longitude' => null]);
        $venue = Venue::factory()->create([
            'location_id' => Location::factory()->create(['address_id' => $address->id])->id,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);

        $editor = app(VenueBackofficeEditor::class);
        $data = array_replace([
            'name' => $venue->name,
            'alias' => $venue->alias,
            'type' => $venue->type->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => $venue->operational_status->value,
            'raw_address' => $address->full_address,
        ], $editor->fields($venue));
        $data['status'] = VenueStatusEnum::CONFIRMED->value;
        $data['location_latitude'] = 55.850321;
        $data['location_longitude'] = 37.439876;

        $confirmed = $editor->save($data, $venue);

        $this->assertSame(VenueStatusEnum::CONFIRMED, $confirmed->status);
        $this->assertSame(ModerationRequestStatusEnum::APPROVED,
            $confirmed->moderationRequests()->firstOrFail()->status);
        $this->assertSame(55.850321, (float) $confirmed->location->address->latitude);
        $this->assertSame(37.439876, (float) $confirmed->location->address->longitude);
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
