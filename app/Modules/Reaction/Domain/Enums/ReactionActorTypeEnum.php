<?php

namespace App\Modules\Reaction\Domain\Enums;

enum ReactionActorTypeEnum: string
{
    case USER = 'user';
    case TELEGRAM = 'telegram';
}
