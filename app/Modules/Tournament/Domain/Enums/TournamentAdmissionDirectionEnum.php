<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentAdmissionDirectionEnum: string
{
    case APPLICATION = 'application';
    case INVITATION = 'invitation';
}
