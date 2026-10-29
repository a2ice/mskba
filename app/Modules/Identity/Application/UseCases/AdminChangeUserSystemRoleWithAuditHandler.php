<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminChangeUserSystemRoleWithAuditHandler
{
    /**
     * @return array{role: string, staff_title: string|null, specialization_ids: list<int>, explicit_grants: array<string, bool>}
     */
    public function snapshot(User $user): array
    {
        $ids = $user->managerSpecializations()->pluck('manager_specializations.id')
            ->map(fn ($id): int => (int) $id)->all();
        sort($ids);
        $grants = $user->operationalPermissions()->get()
            ->mapWithKeys(fn ($row): array => [$row->permission->value => (bool) $row->is_allowed])->all();
        ksort($grants);

        return [
            'role' => $user->system_role->value,
            'staff_title' => $user->staff_title,
            'specialization_ids' => $ids,
            'explicit_grants' => $grants,
        ];
    }

    public function handle(
        User $actor,
        User $target,
        UserSystemRoleEnum $role,
        string $reason,
        ?int $revertedRevisionId = null,
    ): ?AuditLog {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения роли (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $role, $reason, $revertedRevisionId): ?AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $before = $this->snapshot($target);
            app(AdminUpdateUserSystemRoleHandler::class)->handle($actor, $target->id, $role);
            $after = $this->snapshot($target->fresh());
            if ($before === $after) {
                return null;
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => $revertedRevisionId === null
                    ? 'admin_system_role_changed' : 'admin_system_role_reverted',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => array_filter([
                    'reason' => $reason,
                    'reverted_revision_id' => $revertedRevisionId,
                    'target_user_id' => $target->id,
                ], static fn ($value) => $value !== null),
            ]);
        });
    }
}
