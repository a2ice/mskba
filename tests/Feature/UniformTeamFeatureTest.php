<?php

namespace Tests\Feature;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Uniform\Domain\Models\UniformDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class UniformTeamFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'username' => 'team-kit-owner',
            'status' => UserStatusEnum::CONFIRMED,
        ]);
    }

    private function team(User $owner): Team
    {
        $this->actingAs($owner)->post(route('teams.store'), [
            'name' => 'Команда комплекта',
            'sport_types' => ['basketball'],
            'creator_sport_roles' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Team::query()->where('name', 'Команда комплекта')->firstOrFail();
    }

    private function design(array $changes = []): array
    {
        return [
            'name' => 'Командный комплект',
            'primary_color' => '#112233',
            'secondary_color' => '#FFCC00',
            'sizes' => ['M', 'XL', 'XXL'],
            'sleeve_style' => 'sleeveless',
            'logo_source' => 'none',
            'number_enabled' => true,
            'jersey_number_x' => 225,
            'shorts_number_y' => 755,
            ...$changes,
        ];
    }

    private function member(Team $team, User $user, bool $withPermission = false): void
    {
        $contract = Contract::query()->create([
            'family' => ContractFamilyEnum::MEMBERSHIP,
            'name' => 'Участник команды',
            'status' => ContractStatusEnum::ACTIVE,
            'starts_at' => now(),
            'assigned_by' => $team->createdByActor->user_id,
            'assigned_at' => now(),
            'assigner' => UserParticipationRoleAssignerEnum::USER,
            'legal_nature' => 'technical',
            'verification_requirement' => 'none',
        ]);
        $contract->membership()->create([
            'scope_type' => ContractMembershipScopeTypeEnum::TEAM,
            'scope_id' => $team->id,
            'user_id' => $user->id,
            'access_level' => 'responsible',
            'invitation_status' => TeamInvitationStatusEnum::ACCEPTED,
            'sport_roles' => ['player'],
            'jersey_number' => 0,
        ]);
        if ($withPermission) {
            $contract->permissions()->create(['permission' => TeamPermissionEnum::MANAGE_UNIFORMS->value]);
        }
    }

    public function test_team_owner_creates_one_team_design_without_polluting_personal_library(): void
    {
        $owner = $this->owner();
        $team = $this->team($owner);
        $path = '/uniforms/teams/'.$team->id;

        $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('design', null);

        $created = $this->postJson($path, $this->design(['jersey_number' => 999]))->assertOk()
            ->assertJsonPath('design.team_id', $team->id)
            ->assertJsonPath('design.name', 'Командный комплект')
            ->assertJsonPath('design.jersey_number', 1);
        $id = $created->json('design.id');

        $this->postJson($path, $this->design([
            'name' => 'Командный комплект 2', 'secondary_color' => null,
        ]))->assertOk()->assertJsonPath('design.id', $id)
            ->assertJsonPath('design.secondary_color', null);

        $this->assertSame(1, UniformDesign::query()->where('team_id', $team->id)->count());
        $this->getJson('/uniforms/projects')->assertOk()->assertJsonCount(0, 'designs');
        $this->getJson('/uniforms/projects/'.$id)->assertNotFound();
        $this->putJson('/uniforms/projects/'.$id, $this->design())->assertNotFound();
        $this->deleteJson('/uniforms/projects/'.$id)->assertNotFound();
        $this->getJson($path)->assertOk()->assertJsonPath('design.name', 'Командный комплект 2');
    }

    public function test_member_can_view_team_design_and_own_roster_number_but_cannot_edit(): void
    {
        $owner = $this->owner();
        $team = $this->team($owner);
        $this->actingAs($owner)->postJson('/uniforms/teams/'.$team->id, $this->design())->assertOk();
        $member = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->member($team, $member);
        $path = '/uniforms/teams/'.$team->id;

        $this->actingAs($member)->getJson($path)->assertOk()
            ->assertJsonPath('can_edit', false)
            ->assertJsonPath('player_number', '00')
            ->assertJsonPath('design.sizes.2', 'XXL');
        $this->postJson($path, $this->design(['name' => 'Попытка записи']))->assertForbidden();
        $this->assertDatabaseMissing('uniform_designs', ['team_id' => $team->id, 'name' => 'Попытка записи']);
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
    }

    public function test_delegated_team_contract_allows_edit_and_expiration_revokes_it(): void
    {
        $owner = $this->owner();
        $team = $this->team($owner);
        $delegate = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->member($team, $delegate, true);
        $path = '/uniforms/teams/'.$team->id;
        $this->actingAs($delegate)->postJson($path, $this->design())->assertOk()
            ->assertJsonPath('design.team_id', $team->id);
        $this->getJson($path)->assertJsonPath('can_edit', true);

        $membership = $team->memberships()->where('user_id', $delegate->id)->firstOrFail();
        $membership->contract->update(['expires_at' => now()->subMinute()]);
        $this->getJson($path)->assertForbidden();
        $this->postJson($path, $this->design())->assertForbidden();
    }

    public function test_custom_team_logo_is_private_to_members_and_kit_number_is_not_user_supplied(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $team = $this->team($owner);
        $path = '/uniforms/teams/'.$team->id;
        $this->actingAs($owner)->post($path, [
            ...$this->design(['logo_source' => 'custom', 'jersey_number' => 777]),
            'logo' => UploadedFile::fake()->image('team-crest.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('design.jersey_number', 1);
        $this->get('/uniforms/teams/'.$team->id.'/logo')->assertOk()->assertHeader('Content-Type', 'image/webp');

        $member = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->member($team, $member);
        $this->actingAs($member)->get('/uniforms/teams/'.$team->id.'/logo')->assertOk();
        $this->actingAs(User::factory()->create())
            ->get('/uniforms/teams/'.$team->id.'/logo')->assertForbidden();
    }
}
