<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueAssignedManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Portal relationship only: never grants membership permissions or commission. */
final class VenueAssignedManagerService
{
    public function eligible(?User $user): bool
    {
        return $user !== null && $user->canonical_user_id === null
            && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed()
            && in_array($user->system_role, [UserSystemRoleEnum::MANAGER, UserSystemRoleEnum::ADMIN,
                UserSystemRoleEnum::SUPERADMIN], true);
    }

    public function assign(Venue $venue, User $target, User $actor): VenueAssignedManager
    {
        return DB::transaction(function () use ($venue, $target, $actor): VenueAssignedManager {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $actor = $this->authorize($actor);
            abort_unless($venue->allowsOperationalChanges() && $venue->canonical_venue_id === null, 409);
            $target = User::query()->lockForUpdate()->findOrFail($target->canonical()->id);
            if (! $this->eligible($target)) {
                throw ValidationException::withMessages(['user_id' => 'Назначить можно только активного подтверждённого сотрудника портала с управленческой ролью.']);
            }

            $current = $this->currentQuery($venue)->lockForUpdate()->first();
            if ($current !== null && (int) $current->user_id === (int) $target->id) {
                return $current;
            }
            if ($current !== null) {
                $this->end($current, $actor);
            }

            return VenueAssignedManager::query()->create([
                'venue_id' => $venue->id,
                'user_id' => $target->id,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => now(),
                'active_marker' => true,
            ]);
        }, 3);
    }

    /** Require the exact assignment ID so a stale modal cannot revoke a replacement. */
    public function revoke(Venue $venue, VenueAssignedManager $assignment, User $actor): void
    {
        DB::transaction(function () use ($venue, $assignment, $actor): void {
            $venue = Venue::query()->withTrashed()->lockForUpdate()->findOrFail($venue->id);
            $actor = $this->authorize($actor);
            $record = VenueAssignedManager::query()->where('venue_id', $venue->id)
                ->lockForUpdate()->findOrFail($assignment->id);
            if ($record->active_marker === true && $record->ended_at === null) {
                $this->end($record, $actor);
            }
        }, 3);
    }

    /** Internal current assignment; consumers still need to enforce profile privacy. */
    public function current(Venue $venue): ?VenueAssignedManager
    {
        if (! $venue->allowsOperationalChanges() || $venue->canonical_venue_id !== null) {
            return null;
        }
        $record = $this->currentQuery($venue)->with('user')->first();

        return $record !== null && $this->eligible($record->user) ? $record : null;
    }

    private function currentQuery(Venue $venue): \Illuminate\Database\Eloquent\Builder
    {
        return VenueAssignedManager::query()->where('venue_id', $venue->id)
            ->where('active_marker', true)->whereNull('ended_at');
    }

    private function authorize(User $actor): User
    {
        $actor = User::query()->lockForUpdate()->findOrFail($actor->canonical()->id);
        abort_unless($this->eligible($actor) && $actor->system_role === UserSystemRoleEnum::SUPERADMIN, 403);

        return $actor;
    }

    private function end(VenueAssignedManager $assignment, User $actor): void
    {
        $assignment->update(['active_marker' => null, 'ended_at' => now(), 'ended_by_user_id' => $actor->id]);
    }
}
