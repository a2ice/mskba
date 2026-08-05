<?php

namespace Tests\Feature\Team;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeamPendingInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_always_renders_pending_container_and_ajax_response_contains_card(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);

        $creator = User::query()
            ->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)
            ->firstOrFail();
        $candidate = User::factory()->create([
            'username' => 'pending-team-member',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $candidate->profile()->create([
            'first_name' => 'Ожидающий',
            'last_name' => 'Игрок',
        ]);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($creator)
            ->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('data-team-pending-invitations', false)
            ->assertSee('data-pending-invitations-list', false)
            ->assertSee('Отправленных приглашений пока нет.');

        $response = $this->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
            'user_id' => $candidate->id,
            'member_type' => 'player',
            'permissions' => [],
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Приглашение отправлено.')
            ->assertJsonStructure(['invitation' => ['id', 'html']]);

        $this->assertStringContainsString('data-pending-invitation-id=', $response->json('invitation.html'));
        $this->assertStringContainsString('data-pending-invitation-revoke', $response->json('invitation.html'));
        $this->assertStringContainsString('Ожидающий Игрок', $response->json('invitation.html'));

        $this->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertSee('Ожидающий Игрок')
            ->assertSee('@pending-team-member')
            ->assertSee('приглашение ожидает ответа')
            ->assertSee('Отозвать');
    }

    public function test_manager_can_revoke_invitation_and_stale_acceptance_shows_explicit_error(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);

        $creator = User::query()
            ->where('username', GameLifecycleDemoSeeder::ORGANIZER_USERNAME)
            ->firstOrFail();
        $candidate = User::factory()->create([
            'username' => 'revoked-team-member',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $candidate->profile()->create(['first_name' => 'Отозванный', 'last_name' => 'Игрок']);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();

        $this->actingAs($creator)
            ->postJson(route('teams.invitations.store', $team->routeIdentifier()), [
                'user_id' => $candidate->id,
                'member_type' => 'player',
                'permissions' => [],
            ])
            ->assertCreated();

        $membership = $team->memberships()->where('user_id', $candidate->id)->firstOrFail();

        $this->patchJson(route('teams.invitations.respond', $membership->id), [
            'decision' => 'revoke',
        ])->assertOk()
            ->assertJsonPath('message', 'Приглашение отозвано.')
            ->assertJsonPath('membership_id', $membership->id);

        $this->assertSame(TeamInvitationStatusEnum::REVOKED, $membership->fresh()->invitation_status);
        $this->assertSame(ContractStatusEnum::INACTIVE, $membership->contract->fresh()->status);

        $this->get(route('teams.management', $team->routeIdentifier()))
            ->assertOk()
            ->assertDontSee('Отозванный Игрок');

        $this->actingAs($candidate)
            ->patch(route('teams.invitations.respond', $membership->id), ['decision' => 'accept'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Приглашение было отозвано.');

        $this->assertSame(TeamInvitationStatusEnum::REVOKED, $membership->fresh()->invitation_status);
        $this->assertSame(ContractStatusEnum::INACTIVE, $membership->contract->fresh()->status);
    }
}
