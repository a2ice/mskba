<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Identity\Application\UseCases\AdminEditPlayerProfileHandler;
use App\Modules\Identity\Application\UseCases\AdminRevertPlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserPlayerProfileConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
        $user->createProfile([]);
        return $user;
    }

    private function activatePlayer(User $user): void
    {
        $user->participationRoles()->create([
            'role' => UserParticipationRoleEnum::PLAYER,
            'status' => UserParticipationRoleStatusEnum::ACTIVE,
            'assigned_at' => now(),
        ]);
    }

    public function test_superadmin_edits_player_fields_with_reason_and_can_revert(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->activatePlayer($target);
        $this->actingAs($super);

        $revision = app(AdminEditPlayerProfileHandler::class)->handle($super, $target, [
            'height_cm' => 187,
            'body_type' => 'normal',
            'comment' => 'Играет в любительской команде',
        ], 'Исправление данных по обращению игрока');

        $this->assertSame('admin_player_profile_changed', $revision->event);
        $this->assertSame('Исправление данных по обращению игрока', $revision->metadata['reason']);
        $this->assertSame(187, $target->fresh()->playerProfile->height_cm);

        $revert = app(AdminRevertPlayerProfileHandler::class)->handle(
            $super, $target, $revision->id, 'Возвращаем профиль игрока по запросу',
        );
        $this->assertSame('admin_player_profile_reverted', $revert->event);
        $this->assertNull($target->fresh()->playerProfile->height_cm);
        $this->assertNotNull($revision->fresh());
    }

    public function test_player_editor_rejects_inactive_role(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->actingAs($super);
        $this->expectException(ValidationException::class);
        app(AdminEditPlayerProfileHandler::class)->handle(
            $super, $target, ['height_cm' => 186], 'Проверка запрета без роли игрока',
        );
    }

    public function test_player_editor_denies_admin_without_separate_permission(): void
    {
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->activatePlayer($target);
        $this->actingAs($admin);
        $this->expectException(AuthorizationException::class);
        app(AdminEditPlayerProfileHandler::class)->handle(
            $admin, $target, ['height_cm' => 190], 'Проверка раздельного разрешения',
        );
    }

    public function test_player_revert_refuses_to_overwrite_later_edit(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $this->activatePlayer($target);
        $this->actingAs($super);
        $revision = app(AdminEditPlayerProfileHandler::class)->handle(
            $super, $target, ['height_cm' => 185], 'Первое изменение роста игрока',
        );
        $target->fresh()->playerProfile->update(['height_cm' => 191]);

        $this->expectException(ValidationException::class);
        app(AdminRevertPlayerProfileHandler::class)->handle(
            $super, $target, $revision->id, 'Попытка откатить устаревшую ревизию',
        );
    }
}
