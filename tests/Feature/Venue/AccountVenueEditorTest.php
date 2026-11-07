<?php

namespace Tests\Feature\Venue;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Location\Domain\Models\MetroStation;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class AccountVenueEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('themes.active', 'mskba_app');
        app()->forgetInstance(\App\Presentation\Theming\ThemeResolver::class);
        View::replaceNamespace('theme', resource_path('themes/mskba_app/views'));
    }

    private function contract(User $user, Venue $venue, VenuePermissionEnum $permission, ContractStatusEnum $status = ContractStatusEnum::ACTIVE): void
    {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'number' => fake()->unique()->bothify('VENUE-####-????'),
            'name' => 'Venue editor',
            'status' => $status,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'assigner' => UserParticipationRoleAssignerEnum::OTHER,
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $venue->id,
            'user_id' => $user->id,
            'access_level' => VenueMembershipAccessLevelEnum::MANAGER->value,
        ]);
        $contract->permissions()->create(['permission' => $permission->value]);
    }

    public function test_editor_uses_account_layout_sticky_tabs_and_real_banner_instead_of_placeholder(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['name' => 'Школа №1794', 'status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);

        $response = $this->actingAs($user)
            ->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertOk();
        $response->assertSee('app-venue-editor__tabs', false)
            ->assertDontSee('data-entity-banner-editor', false)
            ->assertSee('action="'.route('account.venues.update', $venue->routeIdentifier()).'"', false)
            ->assertSeeInOrder(['Главная', 'Аккаунт', 'Мои контракты', 'Мои площадки', 'Школа №1794'])
            ->assertDontSee('MSKBA App theme')
            ->assertSee('aria-disabled="true"', false);
        $this->get(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'address']))
            ->assertOk()->assertSee('data-venue-metro-picker', false)
            ->assertSee('location[latitude]', false)
            ->assertSee('location[metro_station_ids][]', false);
        $this->get(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'photos']))
            ->assertOk()->assertSee('data-entity-banner-editor', false);
        $this->get(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'facilities']))
            ->assertOk()->assertSee('facilities_present', false);
    }

    public function test_schedule_only_contract_can_access_schedule_but_not_details(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT_SCHEDULE);

        $this->actingAs($user)
            ->get(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'schedule']))
            ->assertOk()->assertSee('data-venue-schedule-form', false)
            ->assertSee('intervals[', false);
        $this->get(route('account.venues.edit', $venue->routeIdentifier()))->assertForbidden();
        $this->put(route('account.venues.update', $venue->routeIdentifier()), [
            'name' => 'Нельзя менять', 'type' => $venue->type->value,
        ])->assertForbidden();
    }

    public function test_superadmin_without_contract_does_not_gain_account_editor_rights(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $this->actingAs($superadmin)->get(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertForbidden();
        $this->put(route('account.venues.update', $venue->routeIdentifier()), [
            'name' => 'Запрещено', 'type' => $venue->type->value,
        ])->assertForbidden();
    }

    public function test_inactive_or_view_only_contract_does_not_enable_editor(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $inactive = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $viewer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->contract($inactive, $venue, VenuePermissionEnum::EDIT, ContractStatusEnum::INACTIVE);
        $this->contract($viewer, $venue, VenuePermissionEnum::VIEW);

        $this->actingAs($inactive)->get(route('account.venues.edit', $venue->routeIdentifier()))->assertForbidden();
        $this->actingAs($viewer)->get(route('account.venues.edit', $venue->routeIdentifier()))->assertForbidden();
    }

    public function test_editor_saves_confirmed_venue_details_as_draft(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'name' => 'До редактирования']);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);

        $this->actingAs($user)
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'editor_tab' => 'general',
                'name' => 'После редактирования',
                'type' => $venue->type->value,
                'short_description' => 'Новое описание',
                'full_description' => 'Подробный текст',
                'tags' => 'бассейн, зал',
            ])->assertRedirect(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'general']));

        $this->assertSame('До редактирования', $venue->fresh()->name);
        $this->assertSame('После редактирования', $venue->fresh()->draftRevision?->payload['details']['name']);
        $this->assertSame('Новое описание', $venue->fresh()->draftRevision?->payload['details']['short_description']);
    }

    public function test_confirmed_passport_changes_are_drafts_but_access_conditions_stay_separate(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['name' => 'Исходная площадка', 'status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);
        $url = route('account.venues.update', $venue->routeIdentifier());
        $newType = collect(\App\Modules\Venue\Domain\Enums\VenueTypeEnum::cases())
            ->first(fn ($type) => $type->value !== $venue->type->value)->value;

        $this->actingAs($user)->put($url, [
            'editor_tab' => 'general',
            'name' => 'Новое название',
            'type' => $newType,
            'short_description' => 'Новое описание',
        ])->assertRedirect(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'general']));

        $venue->refresh();
        $this->assertSame('Исходная площадка', $venue->name);
        $this->assertSame('Новое название', $venue->draftRevision?->payload['details']['name']);
        $this->assertSame($newType, $venue->draftRevision?->payload['details']['type']);

        // These fields are exclusively handled by the separate conditions controller.
        $this->put($url, ['name' => $venue->name, 'type' => $venue->type->value,
            'access_type' => 'free'])->assertForbidden();
        $this->assertSame('Новое название', $venue->fresh()->draftRevision?->payload['details']['name']);
    }

    public function test_confirmed_metro_changes_only_the_draft_and_keeps_published_address(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);
        $station = MetroStation::factory()->create();
        $originalLocationId = $venue->location_id;

        $this->actingAs($user)->put(route('account.venues.metro.update', $venue->routeIdentifier()), [
            'metro_station_ids' => [$station->id],
        ])->assertRedirect(route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'address']));

        $venue->refresh();
        $this->assertSame($originalLocationId, $venue->location_id);
        $this->assertContains($station->id, $venue->draftRevision?->payload['location']['metro_station_ids'] ?? []);
        $this->assertFalse($venue->location?->metroStations()->whereKey($station->id)->exists() ?? false);
    }

    public function test_unconfirmed_metro_updates_do_not_mutate_any_shared_location(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);
        $station = MetroStation::factory()->create();
        $oldLocationId = $venue->location_id;

        $this->actingAs($user)->put(route('account.venues.metro.update', $venue->routeIdentifier()), [
            'metro_station_ids' => [$station->id],
        ])->assertRedirect();

        $venue->refresh();
        $this->assertNotSame($oldLocationId, $venue->location_id);
        $this->assertTrue($venue->location->metroStations()->whereKey($station->id)->exists());
        if ($oldLocationId !== null) {
            $this->assertFalse(
                \App\Modules\Location\Domain\Models\Location::query()->findOrFail($oldLocationId)
                    ->metroStations()->whereKey($station->id)->exists(),
            );
        }
    }

    public function test_metro_endpoint_checks_contract_rights_not_merely_ui_disabling(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);

        $this->actingAs($user)->put(route('account.venues.metro.update', $venue->routeIdentifier()), [
            'metro_station_ids' => [],
        ])->assertForbidden();
    }

    public function test_courts_subpage_retains_account_breadcrumbs_and_editor_tabs(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);
        $this->actingAs($user)
            ->get(route('account.venues.courts.index', $venue->routeIdentifier()))
            ->assertOk()
            ->assertSee('app-venue-editor__tabs', false)
            ->assertSeeInOrder(['Главная', 'Аккаунт', 'Мои контракты', 'Мои площадки', $venue->name, 'Залы и корты']);
    }

    public function test_confirmed_draft_is_submitted_to_moderation_from_the_account_editor(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);

        $edit = route('account.venues.edit', ['alias' => $venue->routeIdentifier(), 'tab' => 'general']);
        $this->actingAs($user)->get($edit)->assertOk()
            ->assertDontSee('Отправить изменения на модерацию');

        $this->put(route('account.venues.update', $venue->routeIdentifier()), [
            'editor_tab' => 'general',
            'name' => $venue->name,
            'type' => $venue->type->value,
            'short_description' => 'Сохранённый черновик',
        ])->assertRedirect($edit);

        $this->get($edit)->assertOk()
            ->assertSee('Есть несогласованные изменения')
            ->assertSee('Отправить изменения на модерацию')
            ->assertSee('action="'.route('account.venues.moderation.submit', $venue->routeIdentifier()).'"', false);

        $this->post(route('account.venues.moderation.submit', $venue->routeIdentifier()), [
            'editor_tab' => 'general',
        ])->assertRedirect($edit)->assertSessionHas('status');

        $venue->refresh();
        $this->assertSame('Сохранённый черновик', $venue->draftRevision?->payload['details']['short_description']);
        $this->assertNotSame('Сохранённый черновик', $venue->short_description);
        $this->assertDatabaseHas('moderation_requests', [
            'subject_id' => $venue->id,
            'venue_revision_id' => $venue->draftRevision?->id,
            'status' => 'pending',
        ]);
        $this->get($edit)->assertOk()->assertSee('Изменения на модерации')
            ->assertDontSee('Отправить изменения на модерацию');

        $this->post(route('account.venues.moderation.submit', $venue->routeIdentifier()))
            ->assertRedirect($edit)->assertSessionHas('error');
        $this->assertSame(1, $venue->moderationRequests()->count());
    }

    public function test_confirmed_address_change_is_saved_to_revision_without_changing_public_location(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);
        $publishedAddress = $venue->location->address->full_address;
        $locationId = $venue->location_id;

        $this->actingAs($user)->put(route('account.venues.update', $venue->routeIdentifier()), [
            'editor_tab' => 'address',
            'name' => $venue->name,
            'type' => $venue->type->value,
            'location' => [
                'raw_address' => 'Москва, Новая улица, 11',
                'address_selected' => '1',
                'city' => 'Москва',
                'street' => 'Новая улица',
                'building' => '11',
                'latitude' => 55.756,
                'longitude' => 37.612,
            ],
        ])->assertRedirect(route('account.venues.edit', [
            'alias' => $venue->routeIdentifier(), 'tab' => 'address',
        ]));

        $venue->refresh();
        $this->assertSame($locationId, $venue->location_id);
        $this->assertSame($publishedAddress, $venue->location->address->full_address);
        $this->assertSame('Москва, Новая улица, 11', $venue->draftRevision?->payload['location']['raw_address']);
    }

    public function test_moderation_action_requires_contract_edit_not_only_superadmin_or_schedule_right(): void
    {
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $superadmin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $this->actingAs($superadmin)
            ->post(route('account.venues.moderation.submit', $venue->routeIdentifier()))
            ->assertForbidden();

        $scheduler = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->contract($scheduler, $venue, VenuePermissionEnum::EDIT_SCHEDULE);
        $this->actingAs($scheduler)
            ->post(route('account.venues.moderation.submit', $venue->routeIdentifier()))
            ->assertForbidden();
    }

    public function test_photo_tab_submission_returns_to_available_general_tab_once_pending(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $this->contract($user, $venue, VenuePermissionEnum::EDIT);

        $this->actingAs($user)->put(route('account.venues.update', $venue->routeIdentifier()), [
            'editor_tab' => 'general', 'name' => $venue->name, 'type' => $venue->type->value,
            'short_description' => 'Черновик',
        ])->assertRedirect();

        $this->post(route('account.venues.moderation.submit', $venue->routeIdentifier()), [
            'editor_tab' => 'photos',
        ])->assertRedirect(route('account.venues.edit', [
            'alias' => $venue->routeIdentifier(), 'tab' => 'general',
        ]));
    }

}
