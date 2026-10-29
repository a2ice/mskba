<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\Services\StaffSpecializationManager;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminUpdateStaffSpecializationsWithAuditHandler
{
    /** @return array{role: string, staff_title: string|null, specialization_ids: list<int>} */
    public function snapshot(User $user): array
    {
        $ids = $user->managerSpecializations()->pluck('manager_specializations.id')
            ->map(fn ($id): int => (int) $id)->all();
        sort($ids);

        return [
            'role' => $user->system_role->value,
            'staff_title' => $user->staff_title,
            'specialization_ids' => $ids,
        ];
    }

    /** @param list<int|string> $ids */
    public function handle(
        User $actor,
        User $target,
        array $ids,
        string $staffTitle,
        string $reason,
        ?int $revertedRevisionId = null,
    ): ?AuditLog {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание изменения (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $ids, $staffTitle, $reason, $revertedRevisionId): ?AuditLog {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
            $before = $this->snapshot($target);
            app(StaffSpecializationManager::class)->sync($actor, $target, $ids, $staffTitle);
            $after = $this->snapshot($target->fresh());
            if ($before === $after) {
                return null;
            }

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => $revertedRevisionId === null
                    ? 'admin_staff_specializations_changed' : 'admin_staff_specializations_reverted',
                'old_values' => $before,
                'new_values' => $after,
                'metadata' => array_filter([
                    'reason' => $reason,
                    'reverted_revision_id' => $revertedRevisionId,
                    'target_user_id' => $target->id,
                ], fn ($value) => $value !== null),
            ]);
        });
    }
}
