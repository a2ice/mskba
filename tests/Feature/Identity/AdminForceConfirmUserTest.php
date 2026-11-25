<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\UseCases\AdminForceConfirmUserHandler;
use App\Modules\Identity\Application\UseCases\AdminUpdateUserStatusHandler;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Events\UserAccountConfirmed;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminForceConfirmUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_exception_preserves_missing_data_and_audits_reason_without_reward_event(): void
    {
        $actor = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $target->createProfile([]);
        Event::fake([UserAccountConfirmed::class]);

        $confirmed = app(AdminForceConfirmUserHandler::class)->handle($actor, $target, 'Проверено по отдельному основанию');

        $this->assertSame(Status::CONFIRMED, $confirmed->status);
        $this->assertSame(Role::USER, $confirmed->system_role);
        $this->assertFalse($confirmed->hasVerifiedPrimaryContact());
        $this->assertNull($confirmed->profile->first_name);
        $this->assertFalse($confirmed->contacts()->exists());
        $this->assertFalse($confirmed->operationalPermissions()->exists());
        Event::assertNotDispatched(UserAccountConfirmed::class);

        $log = AuditLog::query()->where('auditable_type', User::class)
            ->where('auditable_id', $target->id)->where('event', 'admin_user_force_confirmed')->sole();
        $this->assertSame($actor->id, $log->actor->user_id);
        $this->assertSame('Проверено по отдельному основанию', $log->metadata['reason']);
        $this->assertSame(['primary_verified_contact', 'first_name', 'last_name', 'birth_date', 'gender'],
            $log->metadata['missing_requirements']);
        $this->assertSame(['status' => 'unconfirmed'], $log->old_values);
        $this->assertSame(['status' => 'confirmed'], $log->new_values);
    }

    public function test_standard_status_change_does_not_bypass_confirmation_requirements(): void
    {
        $actor = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();

        try {
            app(AdminUpdateUserStatusHandler::class)->handle($actor, $target->id, Status::CONFIRMED);
            $this->fail('Ordinary confirmation must check the requirements.');
        } catch (UserCannotBeChangedException) {
            $this->assertSame(Status::UNCONFIRMED, $target->fresh()->status);
        }
    }

    public function test_only_confirmed_superadmin_may_force_confirm_ordinary_unconfirmed_account(): void
    {
        $super = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $admin = $this->user(Role::ADMIN, Status::CONFIRMED);
        $unconfirmedSuper = $this->user(Role::SUPERADMIN);
        $target = $this->user();
        $blocked = $this->user(Role::USER, Status::BLOCKED);
        $staff = $this->user(Role::MANAGER);
        $deleted = $this->user();
        $deleted->delete();

        $handler = app(AdminForceConfirmUserHandler::class);
        foreach ([[$admin, $target], [$unconfirmedSuper, $target], [$super, $blocked],
            [$super, $staff], [$super, $deleted], [$super, $super]] as [$actor, $user]) {
            try {
                $handler->handle($actor, $user, 'Попытка исключительного подтверждения');
                $this->fail('Unauthorized confirmation must be rejected.');
            } catch (AuthorizationException) {
                $this->assertFalse($handler->canConfirm($actor, $user));
            }
        }

        $this->assertSame(Status::UNCONFIRMED, $target->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin_user_force_confirmed']);
    }

    public function test_reason_is_required_and_second_confirmation_is_rejected(): void
    {
        $actor = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $handler = app(AdminForceConfirmUserHandler::class);

        try {
            $handler->handle($actor, $target, 'нет');
            $this->fail('A meaningful reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $handler->handle($actor, $target, 'Проверено по отдельному основанию');

        $this->expectException(AuthorizationException::class);
        $handler->handle($actor, $target->fresh(), 'Повторное подтверждение аккаунта');
    }

    private function user(Role $role = Role::USER, Status $status = Status::UNCONFIRMED): User
    {
        return User::factory()->create(['system_role' => $role, 'status' => $status]);
    }
}
