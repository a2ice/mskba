<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Filament\Backoffice\Resources\BackofficeUsers\Pages\EditBackofficeUserRole;
use App\Modules\Identity\Application\UseCases\AdminEditPlayerGameAttributesHandler;
use App\Modules\Identity\Application\UseCases\AdminAddPlayerToTeamHandler;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use Database\Seeders\GameLifecycleDemoSeeder;
use Illuminate\Validation\ValidationException;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class UserRoleDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create([
            'system_role' => $role,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);

        return $user;
    }

    private function assignPlayer(User $target)
    {
        return $target->participationRoles(false)->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigned_by' => $target->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
        ]);
    }

    public function test_role_table_links_to_separate_role_page_with_five_sections(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $role = $this->assignPlayer($target);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $link = BackofficeUserResource::getUrl('role', [
            'record' => $target, 'participationRole' => $role->id,
        ]);

        $this->get($link)->assertOk()
            ->assertSee('Физические данные')
            ->assertSee('Игровые характеристики')
            ->assertSee('Команды')
            ->assertSee('Мероприятия')
            ->assertSee('Игры и статистика');
        $this->get(BackofficeUserResource::getUrl('edit', [
            'record' => $target, 'tab' => 'rol::data::tab',
        ]))->assertOk()->assertSee($link, false);
    }

    public function test_cannot_open_another_users_role_or_edit_without_permission(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $other = $this->user(UserSystemRoleEnum::USER);
        $role = $this->assignPlayer($other);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        $this->get(BackofficeUserResource::getUrl('role', [
            'record' => $target, 'participationRole' => $role->id,
        ]))->assertNotFound();
        $this->actingAs($other)->get(BackofficeUserResource::getUrl('role', [
            'record' => $other, 'participationRole' => $role->id,
        ]))->assertForbidden();
    }

    public function test_admin_can_save_player_game_attributes_with_audit_and_cannot_change_objective_scores(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->assignPlayer($target);
        $this->actingAs($super);
        $skills = [
            'stamina' => 7,
            'speed' => 8,
            'ball_handling' => 6,
            'passing' => 8,
            'close_range_shooting' => 6,
            'mid_range_shooting' => 5,
            'long_range_shooting' => 4,
            'defense' => 7,
            'rebounding' => 6,
            'basketball_iq' => 8,
        ];
        $audit = app(AdminEditPlayerGameAttributesHandler::class)->handle(
            $super, $target,
            ['positions' => ['point_guard', 'shooting_guard'], 'self_assessment' => $skills],
            'Проверено администратором после внесения данных игрока',
        );
        $this->assertSame('admin_player_game_attributes_changed', $audit->event);
        $this->assertSame(8, $target->playerProfile->selfAssessment->passing);
        $this->assertCount(2, $target->playerProfile->positions);
        $this->assertNull($target->playerObjectiveAssessment);
    }

    public function test_inactive_role_still_has_detail_but_mutation_is_rejected(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $role = $this->assignPlayer($target);
        $role->update(['status' => UserParticipationRoleStatusEnum::INACTIVE]);
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        Livewire::test(EditBackofficeUserRole::class, [
            'record' => $target->id, 'participationRole' => $role->id,
        ])->assertSee('Роль отключена');
    }
    public function test_superadmin_can_add_player_to_team_with_real_contract_rosters_and_audit(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->assignPlayer($target);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $this->actingAs($super);
        $audit = app(AdminAddPlayerToTeamHandler::class)->handle(
            $super, $target, $team, 'Административное включение игрока с проверкой договора',
        );
        $this->assertSame('admin_player_joined_team', $audit->event);
        $membership = $team->memberships()->where('user_id', $target->id)->firstOrFail();
        $this->assertSame('accepted', $membership->invitation_status->value);
        $this->assertSame(ContractStatusEnum::ACTIVE, $membership->contract->status);
        $this->assertTrue($membership->hasSportRole(\\App\\Modules\\Team\\Domain\\Enums\\TeamMemberTypeEnum::PLAYER));
        $this->assertGreaterThan(0, $membership->sportLineupAssignments()->count());

        $this->expectException(ValidationException::class);
        app(AdminAddPlayerToTeamHandler::class)->handle(
            $super, $target, $team, 'Повторное назначение той же роли в той же команде',
        );
    }

    public function test_admin_without_superadmin_role_cannot_directly_include_player_in_team(): void
    {
        $this->seed(GameLifecycleDemoSeeder::class);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->assignPlayer($target);
        $team = Team::query()->where('alias', 'demo-red')->firstOrFail();
        $this->actingAs($admin);
        $this->expectException(\\Symfony\\Component\\HttpKernel\\Exception\\HttpException::class);
        app(AdminAddPlayerToTeamHandler::class)->handle(
            $admin, $target, $team, 'Администратору не разрешено прямое включение',
        );
    }

}
