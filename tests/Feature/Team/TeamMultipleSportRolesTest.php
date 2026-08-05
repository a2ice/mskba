<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamMultipleSportRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_own_team_without_sport_roles_and_add_them_later(): void
    {
        $owner = User::factory()->create([
            'username' => 'owner-without-sport-role',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда владельца без роли',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда владельца без роли')->firstOrFail();
        $membership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->assertSame(TeamMembershipAccessLevelEnum::OWNER->value, $membership->access_level);
        $this->assertNull($membership->member_type);
        $this->assertSame([], $membership->sportRoleValues());
        $this->assertFalse($membership->is_captain);
        $this->assertFalse($membership->is_default_starter);

        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Спортивные роли участников')
            ->assertSee('Владелец команды')
            ->assertSee('owner-without-sport-role');

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]), [
            'sport_roles' => [
                TeamMemberTypeEnum::PLAYER->value,
                TeamMemberTypeEnum::COACH->value,
                TeamMemberTypeEnum::MANAGER->value,
            ],
            'is_captain' => 1,
            'is_default_starter' => 1,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $membership->refresh();
        $this->assertSame(TeamMemberTypeEnum::PLAYER, $membership->member_type);
        $this->assertSame(
            ['player', 'coach', 'manager'],
            $membership->sportRoleValues(),
        );
        $this->assertTrue($membership->is_captain);
        $this->assertTrue($membership->is_default_starter);
        $this->assertSame(1, $membership->sportLineupAssignments()->count());

        $this->get(route('teams.show', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Тренеры')
            ->assertSee('Менеджеры')
            ->assertSee('Игрок, Тренер, Менеджер');
    }

    public function test_creator_can_select_multiple_roles_during_team_creation(): void
    {
        $owner = User::factory()->create([
            'username' => 'playing-coach-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда играющего тренера',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [
                TeamMemberTypeEnum::PLAYER->value,
                TeamMemberTypeEnum::COACH->value,
            ],
            'creator_is_captain' => 1,
            'creator_is_default_starter' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $membership = Team::query()->where('name', 'Команда играющего тренера')
            ->firstOrFail()->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->assertSame(TeamMemberTypeEnum::PLAYER, $membership->member_type);
        $this->assertSame(['player', 'coach'], $membership->sportRoleValues());
        $this->assertTrue($membership->is_captain);
        $this->assertTrue($membership->is_default_starter);
        $this->assertSame(1, $membership->sportLineupAssignments()->count());
    }

    public function test_non_player_roles_cannot_keep_captain_or_starter_flags(): void
    {
        $owner = User::factory()->create([
            'username' => 'non-player-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда без играющего владельца',
            'creator_sport_roles' => [TeamMemberTypeEnum::MANAGER->value],
            'creator_is_captain' => 1,
            'creator_is_default_starter' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда без играющего владельца')->firstOrFail();
        $membership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();
        $this->assertSame(['manager'], $membership->sportRoleValues());
        $this->assertFalse($membership->is_captain);
        $this->assertFalse($membership->is_default_starter);

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::COACH->value],
            'is_captain' => 1,
        ])->assertSessionHas('error', 'Капитаном и стартовым участником может быть только игрок.');

        $this->assertSame(['manager'], $membership->refresh()->sportRoleValues());
    }
}
