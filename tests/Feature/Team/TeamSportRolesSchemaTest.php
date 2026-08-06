<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TeamSportRolesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_memberships_use_only_sport_roles_column(): void
    {
        $this->assertFalse(Schema::hasColumn('contract_memberships', 'member_type'));
        $this->assertTrue(Schema::hasColumn('contract_memberships', 'sport_roles'));

        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда без legacy-роли',
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда без legacy-роли')->firstOrFail();
        $membership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->assertSame([], $membership->sportRoleValues());

        $this->put(route('teams.members.sports.update', [
            $team->routeIdentifier(),
            $membership->id,
        ]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
        ])->assertSessionHas('status');

        $this->assertSame(['player'], $membership->fresh()->sportRoleValues());
    }
}
