<?php

namespace Tests\Feature\Venue;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Location\Domain\Models\Address;
use App\Modules\Location\Domain\Models\Location;
use App\Modules\Venue\Application\Services\VenueDuplicateDetector;
use App\Modules\Venue\Domain\Enums\VenueDuplicateMatchTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueDuplicateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_detector_creates_pending_duplicate_candidate_for_unconfirmed_venues(): void
    {
        $firstVenue = $this->venue([
            'name' => 'V1',
            'alias' => 'v1',
            'raw_address' => 'Москва, A1',
        ]);
        $secondVenue = $this->venue([
            'name' => 'V1',
            'alias' => 'v1',
            'raw_address' => '  москва, a1  ',
        ]);

        app(VenueDuplicateDetector::class)->detectFor($secondVenue);

        $this->assertDatabaseHas('venue_duplicates', [
            'venue_id' => min($firstVenue->id, $secondVenue->id),
            'duplicate_venue_id' => max($firstVenue->id, $secondVenue->id),
            'matched_by' => VenueDuplicateMatchTypeEnum::ADDRESS->value,
            'status' => VenueDuplicateStatusEnum::PENDING->value,
            'score' => 100,
        ]);
    }

    public function test_detector_does_not_match_venues_of_different_types(): void
    {
        $indoorVenue = $this->venue([
            'name' => 'Школьная площадка',
            'alias' => 'skolnaia-ploshhadka',
            'type' => VenueTypeEnum::SPORTS_HALL,
            'raw_address' => 'Москва, Школьная улица, 1',
        ]);
        $outdoorVenue = $this->venue([
            'name' => 'Школьная площадка',
            'alias' => 'skolnaia-ploshhadka',
            'type' => VenueTypeEnum::STREET_COURT,
            'raw_address' => 'Москва, Школьная улица, 1',
        ]);

        app(VenueDuplicateDetector::class)->detectFor($outdoorVenue);

        $this->assertDatabaseMissing('venue_duplicates', [
            'venue_id' => min($indoorVenue->id, $outdoorVenue->id),
            'duplicate_venue_id' => max($indoorVenue->id, $outdoorVenue->id),
        ]);
    }

    public function test_admin_can_merge_duplicate_candidate_and_merge_is_written_to_audit_log(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $canonical = $this->venue([
            'name' => 'Главная площадка',
            'alias' => 'main-venue',
        ]);
        $duplicate = $this->venue([
            'name' => 'Дубль площадки',
            'alias' => 'main-venue',
        ]);
        $candidate = VenueDuplicate::query()->create([
            'venue_id' => $canonical->id,
            'duplicate_venue_id' => $duplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.duplicates.merge', $candidate), [
                'canonical_venue_id' => $canonical->id,
                'duplicate_venue_id' => $duplicate->id,
            ])
            ->assertRedirect(route('admin.venues.duplicates'));

        $this->assertDatabaseHas('venues', [
            'id' => $canonical->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'canonical_venue_id' => null,
        ]);
        $this->assertDatabaseHas('venues', [
            'id' => $duplicate->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'canonical_venue_id' => $canonical->id,
        ]);
        $this->assertDatabaseHas('venue_duplicates', [
            'id' => $candidate->id,
            'status' => VenueDuplicateStatusEnum::MERGED->value,
            'resolved_by' => $admin->id,
        ]);

        $log = AuditLog::query()
            ->where('auditable_type', Venue::class)
            ->where('auditable_id', $duplicate->id)
            ->where('event', 'merged')
            ->firstOrFail();

        $this->assertSame($canonical->id, $log->new_values['canonical_venue_id']);
        $this->assertSame($candidate->id, $log->metadata['venue_duplicate_id']);
        $this->assertSame('admin.venues.duplicates.merge', $log->metadata['route']);
    }

    public function test_admin_duplicates_page_shows_checkbox_merge_action_and_merge_modal(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $firstVenue = $this->venue(['name' => 'Первая площадка']);
        $secondVenue = $this->venue(['name' => 'Вторая площадка']);

        VenueDuplicate::query()->create([
            'venue_id' => $firstVenue->id,
            'duplicate_venue_id' => $secondVenue->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues.duplicates'))
            ->assertOk()
            ->assertSee('Название')
            ->assertSee('Адрес')
            ->assertSee('Кто создал')
            ->assertSee('Главная')
            ->assertSee('admin-table__check-cell', false)
            ->assertSee('data-venue-duplicates-merge-open', false)
            ->assertSee('data-venue-duplicate-option', false)
            ->assertSee('data-venue-duplicates-merge-modal', false)
            ->assertSee('data-venue-duplicates-preview-open', false)
            ->assertSee('data-venue-duplicates-preview-modal', false)
            ->assertSee('data-group-id', false)
            ->assertSee('Объединить площадки');
    }

    public function test_admin_duplicates_page_marks_assigned_canonical_venue(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $canonical = $this->venue(['name' => 'Главная площадка']);
        $duplicate = $this->venue([
            'name' => 'Дубль площадки',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'canonical_venue_id' => $canonical->id,
        ]);

        VenueDuplicate::query()->create([
            'venue_id' => $canonical->id,
            'duplicate_venue_id' => $duplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::MERGED,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.venues.duplicates'))
            ->assertOk()
            ->assertSee('Главная')
            ->assertSee('admin-canonical-mark', false)
            ->assertSee('aria-label="Главная площадка"', false);
    }

    public function test_admin_can_batch_merge_duplicates_of_one_venue(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $canonical = $this->venue(['name' => 'Главная площадка', 'alias' => 'main-venue']);
        $firstDuplicate = $this->venue(['name' => 'Первый дубль', 'alias' => 'main-venue']);
        $secondDuplicate = $this->venue(['name' => 'Второй дубль', 'alias' => 'main-venue']);

        $firstCandidate = VenueDuplicate::query()->create([
            'venue_id' => $canonical->id,
            'duplicate_venue_id' => $firstDuplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);
        $secondCandidate = VenueDuplicate::query()->create([
            'venue_id' => $canonical->id,
            'duplicate_venue_id' => $secondDuplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.duplicates.merge-batch'), [
                'canonical_venue_id' => $canonical->id,
                'venue_ids' => [$canonical->id, $firstDuplicate->id, $secondDuplicate->id],
            ])
            ->assertRedirect(route('admin.venues.duplicates'));

        $this->assertDatabaseHas('venues', [
            'id' => $canonical->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'canonical_venue_id' => null,
        ]);
        foreach ([$firstDuplicate, $secondDuplicate] as $duplicate) {
            $this->assertDatabaseHas('venues', [
                'id' => $duplicate->id,
                'status' => VenueStatusEnum::UNCONFIRMED->value,
                'canonical_venue_id' => $canonical->id,
            ]);
        }
        foreach ([$firstCandidate, $secondCandidate] as $candidate) {
            $this->assertDatabaseHas('venue_duplicates', [
                'id' => $candidate->id,
                'status' => VenueDuplicateStatusEnum::MERGED->value,
            ]);
        }
    }

    public function test_admin_cannot_batch_merge_duplicates_from_different_venues(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $firstCanonical = $this->venue(['name' => 'Первая главная']);
        $firstDuplicate = $this->venue(['name' => 'Первый дубль']);
        $secondCanonical = $this->venue(['name' => 'Вторая главная']);
        $secondDuplicate = $this->venue(['name' => 'Второй дубль']);

        VenueDuplicate::query()->create([
            'venue_id' => $firstCanonical->id,
            'duplicate_venue_id' => $firstDuplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);
        VenueDuplicate::query()->create([
            'venue_id' => $secondCanonical->id,
            'duplicate_venue_id' => $secondDuplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.venues.duplicates'))
            ->post(route('admin.venues.duplicates.merge-batch'), [
                'canonical_venue_id' => $firstCanonical->id,
                'venue_ids' => [$firstCanonical->id, $firstDuplicate->id, $secondDuplicate->id],
            ])
            ->assertRedirect(route('admin.venues.duplicates'))
            ->assertSessionHas('error', 'Можно объединять только дубли одной площадки.');

        $this->assertDatabaseMissing('venues', [
            'id' => $secondDuplicate->id,
            'canonical_venue_id' => $firstCanonical->id,
        ]);
    }

    public function test_admin_can_change_canonical_venue_until_group_is_confirmed(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $oldCanonical = $this->venue([
            'name' => 'Старая главная',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $newCanonical = $this->venue([
            'name' => 'Новая главная',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'canonical_venue_id' => $oldCanonical->id,
        ]);
        $anotherDuplicate = $this->venue([
            'name' => 'Еще дубль',
            'status' => VenueStatusEnum::UNCONFIRMED,
            'canonical_venue_id' => $oldCanonical->id,
        ]);
        VenueDuplicate::query()->create([
            'venue_id' => $oldCanonical->id,
            'duplicate_venue_id' => $newCanonical->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::MERGED,
            'score' => 70,
        ]);
        VenueDuplicate::query()->create([
            'venue_id' => $oldCanonical->id,
            'duplicate_venue_id' => $anotherDuplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::MERGED,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('admin.venues.duplicates.merge-batch'), [
                'canonical_venue_id' => $newCanonical->id,
                'venue_ids' => [$oldCanonical->id, $newCanonical->id, $anotherDuplicate->id],
            ])
            ->assertRedirect(route('admin.venues.duplicates'));

        $this->assertDatabaseHas('venues', [
            'id' => $newCanonical->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'canonical_venue_id' => null,
        ]);
        $this->assertDatabaseHas('venues', [
            'id' => $oldCanonical->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'canonical_venue_id' => $newCanonical->id,
        ]);
        $this->assertDatabaseHas('venues', [
            'id' => $anotherDuplicate->id,
            'status' => VenueStatusEnum::UNCONFIRMED->value,
            'canonical_venue_id' => $newCanonical->id,
        ]);
    }

    public function test_admin_cannot_change_canonical_when_group_has_confirmed_venue(): void
    {
        $admin = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);
        $confirmedCanonical = $this->venue([
            'name' => 'Подтвержденная главная',
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $duplicate = $this->venue([
            'name' => 'Дубль',
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        VenueDuplicate::query()->create([
            'venue_id' => $confirmedCanonical->id,
            'duplicate_venue_id' => $duplicate->id,
            'matched_by' => VenueDuplicateMatchTypeEnum::NAME,
            'status' => VenueDuplicateStatusEnum::PENDING,
            'score' => 70,
        ]);

        $this
            ->actingAs($admin)
            ->from(route('admin.venues.duplicates'))
            ->post(route('admin.venues.duplicates.merge-batch'), [
                'canonical_venue_id' => $duplicate->id,
                'venue_ids' => [$confirmedCanonical->id, $duplicate->id],
            ])
            ->assertRedirect(route('admin.venues.duplicates'))
            ->assertSessionHas('error', 'В группе уже есть подтвержденная площадка, она должна остаться главной.');

        $this->assertDatabaseHas('venues', [
            'id' => $confirmedCanonical->id,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'canonical_venue_id' => null,
        ]);
        $this->assertDatabaseMissing('venues', [
            'id' => $duplicate->id,
            'canonical_venue_id' => $duplicate->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function venue(array $overrides = []): Venue
    {
        $latitude = (float) ($overrides['latitude'] ?? 55.7000000);
        $longitude = (float) ($overrides['longitude'] ?? 37.6000000);
        unset($overrides['latitude'], $overrides['longitude']);

        $address = Address::factory()->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        $location = Location::factory()->create(['address_id' => $address->id]);

        return Venue::factory()->create(array_merge([
            'type' => VenueTypeEnum::SPORTS_HALL,
            'status' => VenueStatusEnum::UNCONFIRMED,
            'short_description' => 'Описание площадки',
            'raw_address' => 'Москва, Тестовый адрес, 1',
            'location_id' => $location->id,
        ], $overrides));
    }
}
