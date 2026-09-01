<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Models\ContractPermission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Enums\TeamVenueRelationTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Team\Domain\Models\TeamVenueRelation;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamVenueRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_team_people_manage_desired_venues_without_duplicates(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);

        $this->actingAs($creator)
            ->post(route('teams.venues.store', $team->routeIdentifier()), ['venue_id' => $venue->id])
            ->assertRedirect();
        $this->actingAs($creator)
            ->post(route('teams.venues.store', $team->routeIdentifier()), ['venue_id' => $venue->id])
            ->assertRedirect();

        $relation = TeamVenueRelation::query()->sole();
        $this->assertSame(TeamVenueRelationTypeEnum::DESIRED, $relation->relation_type);
        $this->assertSame($creator->canonical()->id, $relation->created_by_user_id);
        $this->actingAs($creator)
            ->get(route('teams.venues.index', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee($venue->name);
        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Желаемые площадки')
            ->assertSee($venue->name);

        $delegateMembership = $team->memberships()
            ->where('user_id', '!=', $creator->id)
            ->with('contract')
            ->firstOrFail();
        ContractPermission::query()->create([
            'contract_id' => $delegateMembership->contract_id,
            'permission' => TeamPermissionEnum::MANAGE_VENUES,
        ]);
        $secondVenue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);

        $this->actingAs($delegateMembership->user)
            ->post(route('teams.venues.store', $team->routeIdentifier()), ['venue_id' => $secondVenue->id])
            ->assertRedirect();
        $this->assertDatabaseHas('team_venue_relations', [
            'team_id' => $team->id,
            'venue_id' => $secondVenue->id,
            'relation_type' => TeamVenueRelationTypeEnum::DESIRED->value,
        ]);

        $this->actingAs($creator)
            ->delete(route('teams.venues.destroy', [$team->routeIdentifier(), $relation->id]))
            ->assertRedirect();
        $this->assertDatabaseMissing('team_venue_relations', ['id' => $relation->id]);
    }

    public function test_venue_relation_rejects_unauthorized_unconfirmed_and_confirmed_removal(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $creator = User::query()->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)->firstOrFail();
        $stranger = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED]);

        $this->actingAs($stranger)
            ->post(route('teams.venues.store', $team->routeIdentifier()), ['venue_id' => $venue->id])
            ->assertForbidden();
        $this->actingAs($creator)
            ->post(route('teams.venues.store', $team->routeIdentifier()), ['venue_id' => $venue->id])
            ->assertUnprocessable();

        $confirmedRelation = TeamVenueRelation::query()->create([
            'team_id' => $team->id,
            'venue_id' => $venue->id,
            'relation_type' => TeamVenueRelationTypeEnum::CONFIRMED,
            'created_by_user_id' => $creator->id,
        ]);
        $this->actingAs($creator)
            ->delete(route('teams.venues.destroy', [$team->routeIdentifier(), $confirmedRelation->id]))
            ->assertUnprocessable();
        $this->assertDatabaseHas('team_venue_relations', ['id' => $confirmedRelation->id]);
    }
}
