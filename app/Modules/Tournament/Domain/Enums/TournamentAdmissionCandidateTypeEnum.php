<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentAdmissionCandidateTypeEnum: string
{
    case TEAM = 'team';
    case USER = 'user';
}
