<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentEntryStatusEnum: string
{
    case ACTIVE = 'active';
    case WITHDRAWN = 'withdrawn';
}
