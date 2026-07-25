<?php

namespace App\Modules\Coordination\Application\Services;

use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Identity\Domain\Models\Actor;

final class CoordinationAccess
{
    public function canManage(CoordinationSession $session, Actor $actor): bool
    {
        if ($actor->user_id === null) {
            return false;
        }

        $session->loadMissing('organizerActor');

        return $session->organizerActor?->user_id === $actor->user_id;
    }
}
