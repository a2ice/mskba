<?php

namespace Tests\Feature\Team;

use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserPrivacySetting;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamInvitationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_invitations_are_allowed_by_default(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $candidate = User::factory()->create([
            'username' => 'default-team-candidate',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $team = $this->createTeam($owner, 'Команда приглашений по умолчанию');

        $this->actingAs($owner)
            ->getJson(route('teams.invitations.search', [
                'team' => $team->routeIdentifier(),
                'q' => 'default-team',
            ]))
            ->assertOk()
            ->assertJsonFragment(['id' => $candidate->getKey()]);

        $this->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->getKey(),
            'member_type' => 'player',
            'permissions' => [],
        ])->assertCreated();
    }

    public function test_user_with_nobody_group_invitation_setting_is_hidden_and_cannot_be_invited_directly(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $candidate = User::factory()->create([
            'username' => 'private-team-candidate',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        UserPrivacySetting::query()->create([
            'user_id' => $candidate->getKey(),
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
            'visibility' => UserPrivacyVisibilityEnum::NOBODY,
        ]);
        $team = $this->createTeam($owner, 'Команда приватных приглашений');

        $this->actingAs($owner)
            ->getJson(route('teams.invitations.search', [
                'team' => $team->routeIdentifier(),
                'q' => 'private-team',
            ]))
            ->assertOk()
            ->assertJsonMissing(['id' => $candidate->getKey()]);

        $this->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->getKey(),
            'member_type' => 'player',
            'permissions' => [],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Пользователь запретил приглашать себя в команды и другие группы.');

        $this->assertFalse($team->memberships()->where('user_id', $candidate->getKey())->exists());
    }

    public function test_selected_user_can_find_and_invite_candidate(): void
    {
        $owner = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $candidate = User::factory()->create([
            'username' => 'selected-team-candidate',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $setting = UserPrivacySetting::query()->create([
            'user_id' => $candidate->getKey(),
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
            'visibility' => UserPrivacyVisibilityEnum::SELECTED_USERS,
        ]);
        $setting->allowedUsers()->attach($owner->getKey());
        $team = $this->createTeam($owner, 'Команда выбранных приглашений');

        $this->actingAs($owner)
            ->getJson(route('teams.invitations.search', [
                'team' => $team->routeIdentifier(),
                'q' => 'selected-team',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $candidate->getKey(),
                'username' => 'selected-team-candidate',
            ]);

        $this->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->getKey(),
            'member_type' => 'player',
            'permissions' => [],
        ])->assertCreated();

        $this->assertTrue($team->memberships()->where('user_id', $candidate->getKey())->exists());
    }

    private function createTeam(User $owner, string $name): Team
    {
        $this->actingAs($owner)
            ->post(route('teams.store'), [
                'name' => $name,
                'creator_sport_roles' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Team::query()->where('name', $name)->firstOrFail();
    }
}
