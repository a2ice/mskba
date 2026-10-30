<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Teams\BackofficeTeamEditor;
use App\Filament\Backoffice\Resources\Teams\BackofficeTeamLogo;
use App\Filament\Backoffice\Resources\Teams\BackofficeTeamMembers;
use App\Filament\Backoffice\Resources\Teams\TeamResource;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\TeamMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Team\Domain\Enums\TeamSportTypeEnum;
use App\Modules\Team\Domain\Models\Team;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BackofficeTeamsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function user(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    private function superadmin(): User
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        return $super;
    }

    private function createTeam(User $owner, array $fields = []): Team
    {
        return app(BackofficeTeamEditor::class)->create([
            'name' => 'Команда Бэк-офиса',
            'owner_user_id' => $owner->id,
            'description' => 'Тестовая команда для настройки',
            'status' => 'active',
            'sport_types' => ['basketball', 'streetball'],
            'colors' => ['home_primary' => '#FF6600'],
            'accepts_join_requests' => true,
            ...$fields,
        ]);
    }

    public function test_permission_and_catalog_are_restricted_to_explicit_team_backoffice_grant(): void
    {
        $regular = $this->user();
        $this->actingAs($regular);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->assertFalse(TeamResource::canViewAny());
        $this->assertFalse(TeamResource::canCreate());

        $this->superadmin();
        $this->assertTrue(TeamResource::canViewAny());
        $this->assertTrue(TeamResource::canCreate());
        $this->assertContains(Permission::BACKOFFICE_TEAM_MANAGE, Permission::backofficeCases());
        $this->get(TeamResource::getUrl('index'))->assertOk()->assertSee('Команды');
        $this->get(TeamResource::getUrl('create'))->assertOk()->assertSee('Владелец команды');
    }

    public function test_create_persists_real_owner_contract_sports_and_colors_and_edit_keeps_owner(): void
    {
        $this->superadmin();
        $owner = $this->user();
        $team = $this->createTeam($owner);
        $this->assertSame(['home_primary' => '#ff6600'], $team->colors);
        $this->assertCount(2, $team->sportProfiles);
        $membership = $team->memberships()->with('contract')->firstOrFail();
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertSame(TeamMembershipAccessLevelEnum::OWNER->value, $membership->access_level);
        $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->status);

        $updated = app(BackofficeTeamEditor::class)->update($team, [
            'name' => 'Команда Бэк-офиса 2',
            'description' => 'Обновлённое описание команды',
            'status' => 'active', 'sport_types' => ['basketball', 'streetball'],
            'colors' => ['home_primary' => '#123456', 'away_primary' => '#abcdef'],
        ]);
        $this->assertSame('Команда Бэк-офиса 2', $updated->name);
        $this->assertSame(['home_primary' => '#123456', 'away_primary' => '#abcdef'], $updated->colors);
        $this->assertSame($owner->id, $updated->memberships()->firstOrFail()->user_id);

        $this->get(TeamResource::getUrl('edit', ['record' => $team]))
            ->assertOk()->assertSee('Логотип и цвета')->assertSee('Турниры и лиги');
    }

    public function test_member_roles_captain_and_roster_are_same_contract_records_as_public_team(): void
    {
        $this->superadmin();
        $team = $this->createTeam($this->user());
        $first = $this->user();
        $second = $this->user();
        $members = app(BackofficeTeamMembers::class);

        $a = $members->add($team, [
            'user_id' => $first->id, 'access_level' => 'player',
            'sport_roles' => ['player', 'manager'], 'is_captain' => true, 'jersey_number' => 8,
        ]);
        $b = $members->add($team, [
            'user_id' => $second->id, 'access_level' => 'player',
            'sport_roles' => ['player'], 'is_captain' => false, 'jersey_number' => 9,
        ]);
        $this->assertTrue((bool) $a->fresh()->is_captain);
        $this->assertSame(['player', 'manager'], $a->sportRoleValues());
        $this->assertSame('captain', $a->fresh()->access_level);

        $members->update($team, $b, [
            'access_level' => 'player', 'sport_roles' => ['player', 'coach'],
            'is_captain' => true, 'jersey_number' => 9,
        ]);
        $this->assertFalse((bool) $a->fresh()->is_captain);
        $this->assertTrue((bool) $b->fresh()->is_captain);

        $members->saveLineup($team, TeamSportTypeEnum::BASKETBALL, [$a->id, $b->id]);
        $profile = $team->sportProfiles()->where('sport_type', 'basketball')->firstOrFail();
        $this->assertSame(2, $profile->lineupMembers()->where('assignment', 'starter')->count());

        $members->remove($team, $a);
        $this->assertSame(ContractStatusEnum::INACTIVE, $a->fresh()->contract->fresh()->status);
        $this->assertSame(0, $a->sportLineupAssignments()->count());
        $this->assertDatabaseHas('contract_memberships', ['id' => $a->id]);
    }

    public function test_cannot_remove_owner_or_delete_playing_captain(): void
    {
        $this->superadmin();
        $team = $this->createTeam($this->user());
        $owner = $team->memberships()->firstOrFail();
        $this->expectException(ValidationException::class);
        app(BackofficeTeamMembers::class)->remove($team, $owner);
    }

    public function test_logo_is_webp_under_500px_and_team_deletion_is_soft_and_reversible(): void
    {
        $this->superadmin();
        $team = $this->createTeam($this->user());
        $image = imagecreatetruecolor(1100, 700);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        app(BackofficeTeamLogo::class)->store($team, $bytes);
        $logo = $team->fresh()->logo;
        $this->assertInstanceOf(Media::class, $logo);
        $this->assertSame('image/webp', $logo->mime);
        $this->assertLessThanOrEqual(500, max($logo->width, $logo->height));
        Storage::disk('public')->assertExists($logo->path);

        app(BackofficeTeamEditor::class)->delete($team);
        $this->assertSoftDeleted('teams', ['id' => $team->id]);
        app(BackofficeTeamEditor::class)->restore($team->fresh() ?? Team::withTrashed()->findOrFail($team->id));
        $this->assertNotNull(Team::query()->find($team->id));
    }
}
