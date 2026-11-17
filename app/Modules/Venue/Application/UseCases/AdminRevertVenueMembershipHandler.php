<?php

namespace App\Modules\Venue\Application\UseCases;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Application\UseCases\AdminEditPersonalProfileHandler;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRevertVenueMembershipHandler
{
    public function handle(User $actor, User $target, int $revisionId, string $reason): AuditLog
    {
        $actor = $actor->canonical();
        $target = $target->canonical();
        app(AdminEditPersonalProfileHandler::class)->authorize($actor, $target);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите основание отката (10–500 символов).']);
        }

        return DB::transaction(function () use ($actor, $target, $revisionId, $reason): AuditLog {
            $revision = AuditLog::query()
                ->whereKey($revisionId)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $target->id)
                ->where('event', 'admin_venue_membership_changed')
                ->firstOrFail();
            $membershipId = (int) ($revision->metadata['membership_id'] ?? 0);
            $membership = ContractMembership::query()->findOrFail($membershipId);
            $editor = app(AdminChangeVenueMembershipHandler::class);
            [$venue, $court] = $editor->scope($membership);
            $venue = Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $membership = ContractMembership::query()
                ->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            app(AdminEditPersonalProfileHandler::class)->authorize(
                $actor, User::query()->whereKey($target->id)->firstOrFail(),
            );
            if (! app(VenueMembershipAccess::class)->activeVenueMembershipsFor($target)->contains('id', $membership->id)
                || (int) $membership->contract_id !== (int) ($revision->metadata['contract_id'] ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Договор больше не действует или был заменён.']);
            }
            [$actualVenue, $actualCourt] = $editor->scope($membership);
            if ($actualVenue->id !== $venue->id || $actualCourt?->id !== $court?->id
                || $venue->id !== (int) ($revision->metadata['venue_id'] ?? 0)
                || $court?->id !== ($revision->metadata['court_id'] ?? null)) {
                throw ValidationException::withMessages(['revision' => 'Область действия договора изменилась.']);
            }
            $before = (array) $revision->old_values;
            $after = (array) $revision->new_values;
            $current = $editor->snapshot($membership->load('contract.permissions'));
            if ($current !== $after) {
                throw ValidationException::withMessages(['revision' => 'Роль или разрешения уже изменились после этой ревизии.']);
            }
            $role = VenueMembershipAccessLevelEnum::tryFrom((string) ($before['role'] ?? ''));
            if (! $role?->isCommercialRole() || $role === VenueMembershipAccessLevelEnum::OWNER
                || ! isset($before['permissions']) || ! is_array($before['permissions'])) {
                throw ValidationException::withMessages(['revision' => 'Ревизия не подходит для автоматического отката.']);
            }
            $permissions = [];
            foreach ($before['permissions'] as $key) {
                $permission = is_string($key) ? VenuePermissionEnum::tryFrom($key) : null;
                if ($permission === null || ! in_array($permission, $role->allowedPermissions(), true)) {
                    throw ValidationException::withMessages(['revision' => 'Ревизия содержит недопустимые разрешения.']);
                }
                $permissions[] = $permission;
            }

            app(ManageVenueMembershipHandler::class)->change($venue, $membership, $role, $actor, $permissions);

            return AuditLog::query()->create([
                'actor_id' => app(CurrentActorResolver::class)->resolveForRequest(request())?->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
                'event' => 'admin_venue_membership_reverted',
                'old_values' => $after,
                'new_values' => $before,
                'metadata' => [
                    'reason' => $reason,
                    'reverted_revision_id' => $revision->id,
                    'membership_id' => $membership->id,
                    'contract_id' => $membership->contract_id,
                    'venue_id' => $venue->id,
                    'court_id' => $court?->id,
                ],
            ]);
        });
    }
}
