<?php

namespace Tests\Feature\Identity;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MyConnectionsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_persists_my_connections_for_both_interaction_settings(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)->get(route('account.privacy.distribution'))
            ->assertOk()->assertSee('value="my_connections"', false);

        $this->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => ['profile' => '1'],
            'distribution_consent' => '1',
            'privacy_options' => [
                'discoverability' => 'everyone',
                'messages' => 'my_connections',
                'group_invitations' => 'my_connections',
            ],
        ])->assertRedirect(route('account'))->assertSessionHasNoErrors();

        foreach (['messages', 'group_invitations'] as $type) {
            $this->assertDatabaseHas('user_privacy_settings', [
                'user_id' => $user->id,
                'type' => $type,
                'visibility' => 'my_connections',
            ]);
        }
    }

    public function test_onboarding_cannot_enable_my_connections_when_search_is_closed(): void
    {
        $user = User::factory()->create([
            'personal_data_distribution_required_at' => now(),
        ]);

        $this->actingAs($user)->put(route('account.privacy.distribution.update'), [
            'action' => 'save',
            'privacy_hierarchy' => '1',
            'public' => ['profile' => '1'],
            'distribution_consent' => '1',
            'privacy_options' => [
                'discoverability' => 'nobody',
                'messages' => 'my_connections',
                'group_invitations' => 'my_connections',
            ],
        ])->assertRedirect(route('account'));

        foreach (['messages', 'group_invitations'] as $type) {
            $this->assertDatabaseHas('user_privacy_settings', [
                'user_id' => $user->id,
                'type' => $type,
                'visibility' => 'nobody',
            ]);
        }
    }

    public function test_accepted_team_connections_control_messages_and_invitation_search_and_store(): void
    {
        $sender = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $recipient = User::factory()->create([
            'username' => 'connected_recipient',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $outsider = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $recipient->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::MESSAGES,
            'visibility' => UserPrivacyVisibilityEnum::MY_CONNECTIONS,
        ]);
        $recipient->privacySettings()->create([
            'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
            'visibility' => UserPrivacyVisibilityEnum::MY_CONNECTIONS,
        ]);

        $sharedTeam = $this->createTeam($sender, 'Связи: первая команда');
        $otherTeam = $this->createTeam($sender, 'Связи: новая команда');
        $membership = $this->membership($recipient, 'team', $sharedTeam->id, 'pending');

        $this->actingAs($sender)->getJson(route('account.notifications.direct.access', $recipient))
            ->assertOk()->assertJsonPath('can_message', false);
        $this->getJson(route('teams.invitations.search', [
            'team' => $otherTeam->routeIdentifier(), 'q' => 'connected_recipient',
        ]))->assertOk()->assertJsonMissing(['id' => $recipient->id]);
        $this->postJson(route('teams.invitations.store', $otherTeam->routeIdentifier()), [
            'user_id' => $recipient->id, 'member_type' => 'player',
        ])->assertUnprocessable();

        $membership->update(['invitation_status' => TeamInvitationStatusEnum::ACCEPTED]);
        $access = app(UserPrivacyAccessService::class);
        $this->assertTrue($access->allows($recipient, $sender, UserPrivacySettingTypeEnum::MESSAGES));
        $this->assertFalse($access->allows($recipient, $outsider, UserPrivacySettingTypeEnum::MESSAGES));
        $this->getJson(route('account.notifications.direct.access', $recipient))
            ->assertOk()->assertJsonPath('can_message', true);
        $this->postJson(route('account.notifications.direct.send', $recipient), [
            'body' => 'Привет, коллега!',
        ])->assertCreated();
        $this->getJson(route('teams.invitations.search', [
            'team' => $otherTeam->routeIdentifier(), 'q' => 'connected_recipient',
        ]))->assertOk()->assertJsonFragment(['id' => $recipient->id]);
        $this->postJson(route('teams.invitations.store', $otherTeam->routeIdentifier()), [
            'user_id' => $recipient->id, 'member_type' => 'player',
        ])->assertCreated();

        $membership->contract->update(['status' => ContractStatusEnum::INACTIVE]);
        $this->assertFalse($access->allows($recipient, $sender, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $this->assertTrue($access->allows($recipient, $sender, UserPrivacySettingTypeEnum::MESSAGES) === false);
        $this->getJson(route('teams.invitations.search', [
            'team' => $this->createTeam($sender, 'Связи: третья команда')->routeIdentifier(),
            'q' => 'connected_recipient',
        ]))->assertOk()->assertJsonMissing(['id' => $recipient->id]);
    }

    public function test_section_trainees_and_coaches_are_mutual_connections_only_while_active(): void
    {
        $coach = User::factory()->create();
        $player = User::factory()->create();
        $otherPlayer = User::factory()->create();
        $stranger = User::factory()->create();
        $section = SportsSection::factory()->create(['status' => SportsSectionStatusEnum::ACTIVE]);
        $coachMembership = $this->membership($coach, 'sports_section', $section->id, 'accepted');
        $first = $section->traineeMemberships()->create([
            'user_id' => $player->id,
            'status' => TraineeMembershipStatusEnum::ACTIVE,
            'joined_at' => now(),
        ]);
        $section->traineeMemberships()->create([
            'user_id' => $otherPlayer->id,
            'status' => TraineeMembershipStatusEnum::ACTIVE,
            'joined_at' => now(),
        ]);

        $access = app(UserPrivacyAccessService::class);
        foreach ([$coach, $player, $otherPlayer] as $recipient) {
            $recipient->privacySettings()->create([
                'type' => UserPrivacySettingTypeEnum::GROUP_INVITATIONS,
                'visibility' => UserPrivacyVisibilityEnum::MY_CONNECTIONS,
            ]);
        }

        $this->assertTrue($access->allows($coach, $player, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $this->assertTrue($access->allows($player, $coach, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $this->assertTrue($access->allows($player, $otherPlayer, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $this->assertFalse($access->allows($player, $stranger, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));

        $first->update(['status' => TraineeMembershipStatusEnum::INACTIVE, 'left_at' => now()]);
        $this->assertFalse($access->allows($player, $coach, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $this->assertFalse($access->allows($player, $otherPlayer, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
        $coachMembership->contract->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($access->allows($coach, $otherPlayer, UserPrivacySettingTypeEnum::GROUP_INVITATIONS));
    }

    private function createTeam(User $owner, string $name): Team
    {
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => $name,
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Team::query()->where('name', $name)->firstOrFail();
    }

    private function membership(User $user, string $scopeType, int $scopeId, string $invitationStatus): ContractMembership
    {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'name' => 'Подтверждение участия',
            'status' => ContractStatusEnum::ACTIVE,
            'verification_requirement' => 'none',
        ]);

        return $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::from($scopeType),
            'scope_id' => $scopeId,
            'user_id' => $user->id,
            'access_level' => 'player',
            'sport_roles' => [],
            'invitation_status' => TeamInvitationStatusEnum::from($invitationStatus),
        ]);
    }
}
