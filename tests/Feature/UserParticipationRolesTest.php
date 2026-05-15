<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserParticipationRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_system_role_and_participation_roles_are_cast_and_related(): void
    {
        $assigner = User::factory()->confirmed()->create([
            'system_role' => UserSystemRoleEnum::ADMIN,
        ]);

        $user = User::factory()->confirmed()->create([
            'system_role' => UserSystemRoleEnum::USER,
        ]);

        $participationRole = UserParticipationRole::query()->create([
            'user_id' => $user->id,
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_by' => $assigner->id,
            'assigner' => UserParticipationRoleAssignerEnum::USER,
            'comment' => 'Основной игрок',
        ]);

        $user = $user->fresh()->load('participationRoles.assignedByUser');

        $this->assertSame(UserSystemRoleEnum::USER, $user->system_role);
        $this->assertCount(1, $user->participationRoles);
        $this->assertSame(UserParticipationRoleEnum::PLAYER, $participationRole->fresh()->role);
        $this->assertSame(UserParticipationRoleStatusEnum::ACTIVE, $participationRole->fresh()->status);
        $this->assertSame(UserParticipationRoleAssignerEnum::USER, $participationRole->fresh()->assigner);
        $this->assertSame($assigner->id, $user->participationRoles->first()->assignedByUser?->id);
    }

    public function test_user_can_have_nullable_system_role(): void
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::UNCONFIRMED,
            'system_role' => null,
        ]);

        $this->assertNull($user->fresh()->system_role);
    }
}
