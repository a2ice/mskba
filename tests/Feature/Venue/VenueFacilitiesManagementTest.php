<?php

namespace Tests\Feature\Venue;

use App\Modules\Admin\Application\Services\VenueRevisionDiffBuilder;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueMarkingConditionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Amenity;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueFacilitiesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_owner_can_save_characteristics_and_applicable_amenities(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'type' => VenueTypeEnum::STREET_COURT,
            'status' => VenueStatusEnum::UNCONFIRMED,
        ]);
        $lighting = Amenity::query()->where('alias', 'lighting')->firstOrFail();
        $parking = Amenity::query()->where('alias', 'parking')->firstOrFail();

        $this->actingAs($user)
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => VenueTypeEnum::STREET_COURT->value,
                'short_description' => $venue->short_description,
                'full_description' => $venue->full_description,
                'characteristics' => [
                    'hoops_count' => 2,
                    'hoops_condition' => 4,
                    'surface_condition' => 3,
                    'marking_condition' => VenueMarkingConditionEnum::GOOD->value,
                ],
                'amenity_ids' => [$lighting->id, $parking->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('venue_characteristics', [
            'venue_id' => $venue->id,
            'hoops_count' => 2,
            'hoops_condition' => 4,
            'surface_condition' => 3,
            'marking_condition' => 'good',
            'first_hoop_marking' => 'good',
            'second_hoop_marking' => null,
        ]);
        $this->assertDatabaseHas('venue_amenities', [
            'venue_id' => $venue->id,
            'amenity_id' => $lighting->id,
        ]);
        $this->assertDatabaseHas('venue_amenities', [
            'venue_id' => $venue->id,
            'amenity_id' => $parking->id,
        ]);
    }

    public function test_confirmed_venue_saves_facilities_to_revision_until_moderation(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'type' => VenueTypeEnum::SPORTS_HALL,
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $shower = Amenity::query()->where('alias', 'shower')->firstOrFail();

        $this->actingAs($user)
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => $venue->short_description,
                'full_description' => $venue->full_description,
                'characteristics' => [
                    'hoops_count' => 1,
                    'hoops_condition' => 5,
                    'surface_condition' => 4,
                    'first_hoop_marking' => VenueMarkingConditionEnum::GOOD->value,
                ],
                'amenity_ids' => [$shower->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('venue_characteristics', ['venue_id' => $venue->id]);
        $this->assertDatabaseMissing('venue_amenities', [
            'venue_id' => $venue->id,
            'amenity_id' => $shower->id,
        ]);

        $revision = $venue->revisions()->whereNull('applied_at')->latest('id')->firstOrFail();
        $this->assertSame(1, $revision->payload['facilities']['characteristics']['hoops_count']);
        $this->assertSame([$shower->id], $revision->payload['facilities']['amenity_ids']);
    }

    public function test_moderation_diff_includes_changed_facilities_and_amenities(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'type' => VenueTypeEnum::SPORTS_HALL,
            'status' => VenueStatusEnum::CONFIRMED,
        ]);
        $venue->characteristics()->create([
            'hoops_count' => 1,
            'hoops_condition' => 3,
            'surface_condition' => 2,
            'marking_condition' => VenueMarkingConditionEnum::PARTIAL,
            'first_hoop_marking' => VenueMarkingConditionEnum::PARTIAL,
        ]);
        $parking = Amenity::query()->where('alias', 'parking')->firstOrFail();
        $shower = Amenity::query()->where('alias', 'shower')->firstOrFail();
        $venue->amenities()->sync([$parking->id]);

        $this->actingAs($user)
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => VenueTypeEnum::SPORTS_HALL->value,
                'short_description' => $venue->short_description,
                'full_description' => $venue->full_description,
                'characteristics' => [
                    'hoops_count' => 2,
                    'hoops_condition' => 5,
                    'surface_condition' => 4,
                    'marking_condition' => VenueMarkingConditionEnum::GOOD->value,
                ],
                'amenity_ids' => [$shower->id],
            ])
            ->assertSessionHasNoErrors();

        $revision = $venue->revisions()->whereNull('applied_at')->latest('id')->firstOrFail();
        $diff = app(VenueRevisionDiffBuilder::class)->build($venue->fresh(), $revision);
        $fields = collect($diff['fields'])->keyBy('label');

        $this->assertTrue($diff['has_changes']);
        $this->assertSame(['before' => '1', 'after' => '2'], collect($fields->get('Количество колец'))->only(['before', 'after'])->all());
        $this->assertSame(['before' => '3 из 5', 'after' => '5 из 5'], collect($fields->get('Состояние колец'))->only(['before', 'after'])->all());
        $this->assertSame(['before' => '2 из 5', 'after' => '4 из 5'], collect($fields->get('Состояние покрытия'))->only(['before', 'after'])->all());
        $this->assertSame(['before' => 'Неполная', 'after' => 'Хорошая'], collect($fields->get('Разметка'))->only(['before', 'after'])->all());
        $this->assertSame(['before' => $parking->name, 'after' => $shower->name], collect($fields->get('Удобства'))->only(['before', 'after'])->all());
    }

    public function test_outdoor_venue_rejects_indoor_amenity(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'type' => VenueTypeEnum::STREET_COURT,
        ]);
        $shower = Amenity::query()->where('alias', 'shower')->firstOrFail();

        $this->actingAs($user)
            ->from(route('account.venues.edit', $venue->routeIdentifier()))
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => VenueTypeEnum::STREET_COURT->value,
                'amenity_ids' => [$shower->id],
            ])
            ->assertRedirect(route('account.venues.edit', $venue->routeIdentifier()))
            ->assertSessionHas('error', 'Выбранная опция не подходит для этого типа площадки.');

        $this->assertDatabaseMissing('venue_amenities', [
            'venue_id' => $venue->id,
            'amenity_id' => $shower->id,
        ]);
    }

    public function test_legacy_second_marking_is_ignored_in_favour_of_single_marking(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
        ]);

        $this->actingAs($user)
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => $venue->type->value,
                'characteristics' => [
                    'hoops_count' => 1,
                    'marking_condition' => VenueMarkingConditionEnum::PARTIAL->value,
                    'second_hoop_marking' => VenueMarkingConditionEnum::GOOD->value,
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('venue_characteristics', [
            'venue_id' => $venue->id,
            'hoops_count' => 1,
            'marking_condition' => VenueMarkingConditionEnum::PARTIAL->value,
            'second_hoop_marking' => null,
        ]);
    }

    public function test_another_user_cannot_change_facilities(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($owner),
        ]);

        $this->actingAs($intruder)
            ->from(route('account.venues.edit', $venue->routeIdentifier()))
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'facilities_present' => '1',
                'name' => $venue->name,
                'type' => $venue->type->value,
                'characteristics' => [
                    'hoops_count' => 2,
                ],
            ])
            ->assertSessionHas('error', 'Доступ запрещен');

        $this->assertDatabaseMissing('venue_characteristics', ['venue_id' => $venue->id]);
    }

    public function test_regular_details_edit_preserves_existing_facilities(): void
    {
        $user = User::factory()->create();
        $venue = Venue::factory()->create([
            'created_by_actor_id' => $this->actorIdFor($user),
            'type' => VenueTypeEnum::STREET_COURT,
        ]);
        $lighting = Amenity::query()->where('alias', 'lighting')->firstOrFail();
        $venue->amenities()->sync([$lighting->id]);

        $this->actingAs($user)
            ->put(route('account.venues.update', $venue->routeIdentifier()), [
                'name' => $venue->name.' обновлена',
                'type' => VenueTypeEnum::STREET_COURT->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('venue_amenities', [
            'venue_id' => $venue->id,
            'amenity_id' => $lighting->id,
        ]);
    }

    private function actorIdFor(User $user): int
    {
        return app(CurrentActorResolver::class)->resolve($user, null)->id;
    }
}
