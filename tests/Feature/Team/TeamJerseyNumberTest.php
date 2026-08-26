<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamJerseyNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_player_can_have_different_jersey_numbers_in_different_teams(): void
    {
        $owner = User::factory()->create([
            'username' => 'jersey-number-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда Ноль',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда 777',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $firstTeam = Team::query()->where('name', 'Команда Ноль')->firstOrFail();
        $secondTeam = Team::query()->where('name', 'Команда 777')->firstOrFail();
        $firstMembership = $firstTeam->memberships()->where('user_id', $owner->id)->firstOrFail();
        $secondMembership = $secondTeam->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->put(route('teams.members.sports.update', [$firstTeam->routeIdentifier(), $firstMembership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'jersey_number' => 0,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->put(route('teams.members.sports.update', [$secondTeam->routeIdentifier(), $secondMembership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'jersey_number' => 777,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $firstMembership->refresh();
        $secondMembership->refresh();

        $this->assertSame(0, $firstMembership->jersey_number);
        $this->assertSame('00', $firstMembership->formattedJerseyNumber());
        $this->assertSame(777, $secondMembership->jersey_number);
        $this->assertSame('777', $secondMembership->formattedJerseyNumber());

        $this->get(route('teams.show', $firstTeam->routeIdentifier()))
            ->assertOk()
            ->assertSee('№00');

        $this->get(route('teams.show', $secondTeam->routeIdentifier()))
            ->assertOk()
            ->assertSee('№777');

        $this->put(route('teams.members.sports.update', [$secondTeam->routeIdentifier(), $secondMembership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'is_captain' => 1,
            'jersey_number' => 0,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->assertSame(0, $secondMembership->fresh()->jersey_number);
    }

    public function test_duplicate_jersey_number_is_rejected_within_same_team(): void
    {
        $owner = User::factory()->create([
            'username' => 'jersey-duplicate-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $candidate = User::factory()->create([
            'username' => 'jersey-duplicate-candidate',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $candidate->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
            'visibility' => UserPrivacyVisibilityEnum::EVERYONE,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда уникальных номеров',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда уникальных номеров')->firstOrFail();
        $ownerMembership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $ownerMembership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'jersey_number' => 0,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->actingAs($owner)->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->id,
            'member_type' => TeamMemberTypeEnum::PLAYER->value,
        ])->assertCreated();

        $candidateMembership = $team->memberships()->where('user_id', $candidate->id)->firstOrFail();

        $this->actingAs($candidate)->patch(route('teams.invitations.respond', $candidateMembership->id), [
            'decision' => 'accept',
        ])->assertRedirect();

        $this->actingAs($owner)->put(route('teams.members.sports.update', [$team->routeIdentifier(), $candidateMembership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'jersey_number' => 0,
        ])->assertSessionHas('error', 'Номер №00 уже занят другим участником команды.');

        $this->assertNull($candidateMembership->fresh()->jersey_number);

        $this->actingAs($owner)->put(route('teams.members.sports.update', [$team->routeIdentifier(), $candidateMembership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'jersey_number' => 24,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $this->assertSame(24, $candidateMembership->fresh()->jersey_number);
    }

    public function test_jersey_number_is_nullable_and_limited_to_zero_through_999(): void
    {
        $owner = User::factory()->create([
            'username' => 'jersey-number-validation-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);

        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда проверки номера',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $team = Team::query()->where('name', 'Команда проверки номера')->firstOrFail();
        $membership = $team->memberships()->where('user_id', $owner->id)->firstOrFail();

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'jersey_number' => 9,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $membership->refresh();
        $this->assertSame(9, $membership->jersey_number);
        $this->assertSame('09', $membership->formattedJerseyNumber());

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'is_captain' => 1,
            'jersey_number' => 1000,
        ])->assertSessionHasErrors('jersey_number');

        $this->assertSame(9, $membership->fresh()->jersey_number);

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'is_captain' => 1,
            'jersey_number' => -1,
        ])->assertSessionHasErrors('jersey_number');

        $this->assertSame(9, $membership->fresh()->jersey_number);

        $this->put(route('teams.members.sports.update', [$team->routeIdentifier(), $membership->id]), [
            'sport_roles' => [TeamMemberTypeEnum::PLAYER->value],
            'is_captain' => 1,
            'jersey_number' => null,
        ])->assertSessionHas('status')->assertSessionHasNoErrors();

        $membership->refresh();
        $this->assertNull($membership->jersey_number);
        $this->assertNull($membership->formattedJerseyNumber());
    }
}
