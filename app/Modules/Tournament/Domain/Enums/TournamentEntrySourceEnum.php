<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentEntrySourceEnum: string
{
    case TEAM = 'team';
    case ASSEMBLED = 'assembled';
    case INDIVIDUAL = 'individual';
}
