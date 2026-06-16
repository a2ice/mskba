<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\ActorClaim;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserFingerprint;
use Illuminate\Database\QueryException;

final class ClaimGuestActorsForUserHandler
{
    public function handle(User $user, ?UserFingerprint $fingerprint, ?Actor $claimedByActor = null): void
    {
        if ($fingerprint === null) {
            return;
        }

        $now = now();

        Actor::query()
            ->where('type', ActorTypeEnum::GUEST)
            ->whereNull('user_id')
            ->where('user_fingerprint_id', $fingerprint->id)
            ->whereDoesntHave('claims')
            ->each(function (Actor $guestActor) use ($user, $claimedByActor, $now): void {
                try {
                    ActorClaim::query()->create([
                        'claimed_actor_id' => $guestActor->id,
                        'claimed_by_user_id' => $user->id,
                        'claimed_by_actor_id' => $claimedByActor?->id,
                        'claimed_at' => $now,
                    ]);
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) !== '23000') {
                        throw $e;
                    }
                }
            });
    }
}
