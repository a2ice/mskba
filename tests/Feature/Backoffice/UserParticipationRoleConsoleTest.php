<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Application\UseCases\AdminEditParticipationRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertParticipationRoleHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

final class UserParticipationRoleConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $systemRole): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $systemRole,
        ]);
    }

    private function role(User $user): \App\Modules\Identity\Domain\Models\UserParticipationRole
    {
        return $user->participationRoles()->create([
            'role' => UserParticipationRoleEnum::COACH,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
            'assigner' => UserParticipationRoleAssignerEnum::FLOW,
            'comment' => 'До проверки',
        ]);
    }

    public function test_superadmin_changes_coach_role_with_reason_and_restores_prior_state(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $role = $this->role($target);
        $this->actingAs($super);

        $revision = app(AdminEditParticipationRoleHandler::class)->handle(
            $super, $target, $role->id, 'inactive', 'Временно приостановлена',
            'Заявка на временную деактивацию роли',
        );
        $this->assertSame('admin_participation_role_changed', $revision->event);
        $this->assertSame('Заявка на временную деактивацию роли', $revision->metadata['reason']);
        $this->assertSame(UserParticipationRoleStatusEnum::INACTIVE, $role->fresh()->status);

        $revert = app(AdminRevertParticipationRoleHandler::class)->handle(
            $super, $target, $revision->id, 'Возврат роли тренера в активное состояние',
        );
        $this->assertSame('admin_participation_role_reverted', $revert->event);
        $this->assertSame(UserParticipationRoleStatusEnum::ACTIVE, $role->fresh()->status);
        $this->assertSame('До проверки', $role->fresh()->comment);
        $this->assertNotNull($revision->fresh());
    }

    public function test_admin_without_personal_edit_permission_is_denied_coach_role_mutation(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $role = $this->role($target);
        $this->actingAs($admin);
        $this->expectException(AuthorizationException::class);
        app(AdminEditParticipationRoleHandler::class)->handle(
            $admin, $target, $role->id, 'inactive', null,
            'Проверка обязательного права редактирования',
        );
    }

    public function test_forged_role_id_for_another_user_is_rejected(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $stranger = $this->user(UserSystemRoleEnum::USER);
        $role = $this->role($stranger);
        $this->actingAs($super);
        $this->expectException(ModelNotFoundException::class);
        app(AdminEditParticipationRoleHandler::class)->handle(
            $super, $target, $role->id, 'inactive', null,
            'Проверка подменённого идентификатора роли',
        );
    }

    public function test_revert_conflicts_with_later_status_change(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $role = $this->role($target);
        $this->actingAs($super);
        $revision = app(AdminEditParticipationRoleHandler::class)->handle(
            $super, $target, $role->id, 'inactive', null,
            'Первоначальная деактивация роли тренера',
        );
        $role->fresh()->update(['status' => UserParticipationRoleStatusEnum::ACTIVE]);

        $this->expectException(ValidationException::class);
        app(AdminRevertParticipationRoleHandler::class)->handle(
            $super, $target, $revision->id, 'Проверка отката устаревшей ревизии роли',
        );
    }
}
