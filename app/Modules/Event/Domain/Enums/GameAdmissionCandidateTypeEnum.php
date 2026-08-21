<?php

namespace App\Modules\Event\Domain\Enums;

enum GameAdmissionCandidateTypeEnum: string
{
    case TEAM = 'team';
    case USER = 'user';
}
