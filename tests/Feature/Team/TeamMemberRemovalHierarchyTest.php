<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamMemberRemovalHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_responsible_cannot_remove_owner_or_equal_level_but_can_remove_lower_level(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $responsibleA = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $responsibleB = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $coach = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Иерархия удаления',
            'creator_sport_roles' => [],
        ])->assertRedirect();
        $team = Team::query()->where('name', 'Иерархия удаления')->firstOrFail();
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $responsibleAMembership = $this->inviteAndAccept(
            $owner,
            $responsibleA,
            $team,
            'manager',
            ['team.members.remove'],
        );
        $responsibleBMembership = $this->inviteAndAccept(
            $owner,
            $responsibleB,
            $team,
            'manager',
        );
        $coachMembership = $this->inviteAndAccept($owner, $coach, $team, 'coach');

        $this->assertSame(
            TeamMembershipAccessLevelEnum::RESPONSIBLE->value,
            $responsibleAMembership->access_level,
        );
        $this->assertSame(
            TeamMembershipAccessLevelEnum::RESPONSIBLE->value,
            $responsibleBMembership->access_level,
        );
        $this->assertSame(TeamMembershipAccessLevelEnum::COACH->value, $coachMembership->access_level);

        $this->actingAs($responsibleA)
            ->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $ownerMembership->id]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Владельца команды удалить нельзя.');

        $this->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $responsibleBMembership->id]))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Нельзя исключить участника с равным или более высоким уровнем управления.',
            );

        $this->deleteJson(route('teams.members.destroy', [$team->routeIdentifier(), $coachMembership->id]))
            ->assertOk()
            ->assertJsonPath('membership_id', $coachMembership->id);
    }

    private function inviteAndAccept(
        User $owner,
        User $candidate,
        Team $team,
        string $memberType,
        array $permissions = [],
    ): ContractMembership {
        $this->actingAs($owner)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->id,
            'member_type' => $memberType,
            'permissions' => $permissions,
        ])->assertCreated();

        $membership = $team->memberships()->where('user_id', $candidate->id)->firstOrFail();
        $this->actingAs($candidate)->patch(
            route('teams.invitations.respond', $membership->id),
            ['decision' => 'accept'],
        )->assertRedirect();

        return $membership->fresh();
    }
}
