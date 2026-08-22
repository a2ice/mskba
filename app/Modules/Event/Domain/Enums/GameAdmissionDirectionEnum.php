<?php

namespace App\Modules\Event\Domain\Enums;

enum GameAdmissionDirectionEnum: string
{
    case APPLICATION = 'application';
    case INVITATION = 'invitation';
    case SELECTION = 'selection';
}
