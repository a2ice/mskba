<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Events\VenueUserRestrictionImposed;
use App\Modules\Venue\Domain\Events\VenueUserRestrictionRevoked;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueUserRestriction;
use Illuminate\Support\Facades\DB;

final class VenueUserRestrictionService
{
    public function active(
        Venue $venue,
        User $user,
        VenueUserRestrictionTypeEnum $type,
    ): ?VenueUserRestriction {
        $user = $user->canonical();

        return VenueUserRestriction::query()
            ->where('venue_id', $venue->id)
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('active_marker', true)
            ->first();
    }

    public function isRestricted(Venue $venue, User $user, VenueUserRestrictionTypeEnum $type): bool
    {
        return $this->active($venue, $user, $type) !== null;
    }

    public function impose(
        Venue $venue,
        User $target,
        VenueUserRestrictionTypeEnum $type,
        string $reason,
        User $administrator,
    ): VenueUserRestriction {
        $administrator = $this->administrator($administrator);
        $target = $target->canonical();

        return DB::transaction(function () use ($venue, $target, $type, $reason, $administrator): VenueUserRestriction {
            Venue::query()->lockForUpdate()->findOrFail($venue->id);

            $existing = VenueUserRestriction::query()
                ->where('venue_id', $venue->id)
                ->where('user_id', $target->id)
                ->where('type', $type->value)
                ->where('active_marker', true)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'reason' => trim($reason),
                    'imposed_by_user_id' => $administrator->id,
                    'imposed_at' => now(),
                ])->save();
                $restriction = $existing->refresh();
            } else {
                $restriction = VenueUserRestriction::query()->create([
                    'venue_id' => $venue->id,
                    'user_id' => $target->id,
                    'type' => $type,
                    'reason' => trim($reason),
                    'imposed_by_user_id' => $administrator->id,
                    'imposed_at' => now(),
                    'active_marker' => true,
                ]);
            }

            DB::afterCommit(static fn () => event(new VenueUserRestrictionImposed($restriction->id)));

            return $restriction;
        });
    }

    public function revoke(
        VenueUserRestriction $restriction,
        User $administrator,
        ?string $reason = null,
    ): VenueUserRestriction {
        $administrator = $this->administrator($administrator);

        return DB::transaction(function () use ($restriction, $administrator, $reason): VenueUserRestriction {
            $restriction = VenueUserRestriction::query()->lockForUpdate()->findOrFail($restriction->id);
            if (! $restriction->active_marker) {
                return $restriction;
            }

            $restriction->forceFill([
                'active_marker' => null,
                'revoked_by_user_id' => $administrator->id,
                'revoked_at' => now(),
                'revoke_reason' => filled($reason) ? trim((string) $reason) : null,
            ])->save();
            $restriction = $restriction->refresh();

            DB::afterCommit(static fn () => event(new VenueUserRestrictionRevoked($restriction->id)));

            return $restriction;
        });
    }

    private function administrator(User $user): User
    {
        $user = $user->canonical();
        abort_unless(
            $user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN),
            403,
        );

        return $user;
    }
}
