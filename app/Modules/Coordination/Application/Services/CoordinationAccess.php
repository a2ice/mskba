<?php

namespace App\Modules\Coordination\Application\Services;

use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\PlayInterest\Domain\Models\PlayGroupAssembly;

final class CoordinationAccess
{
    public function canManage(CoordinationSession $session, Actor $actor): bool
    {
        if ($actor->user_id === null) {
            return false;
        }

        // A group-scoped session is governed by the *current* elected group
        // administrator. Re-election revokes old group powers immediately.
        $assembly = PlayGroupAssembly::query()
            ->where('coordination_session_id', $session->id)
            ->with('opportunity')->first();
        if ($assembly !== null) {
            return $assembly->opportunity?->isAdministrator($actor->user) === true;
        }

        $session->loadMissing('organizerActor');

        return $session->organizerActor?->user_id === $actor->user_id;
    }
}
