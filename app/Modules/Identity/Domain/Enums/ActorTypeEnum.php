<?php

namespace App\Modules\Identity\Domain\Enums;

enum ActorTypeEnum: string
{
    case GUEST = 'guest';
    case USER = 'user';
    case SYSTEM = 'system';
}
