<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenuePermissionEnum: string
{
    case VIEW = 'view';
    case EDIT = 'edit';
    case EDIT_SCHEDULE = 'edit.schedule';
}
