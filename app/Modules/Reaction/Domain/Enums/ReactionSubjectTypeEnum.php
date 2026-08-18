<?php

namespace App\Modules\Reaction\Domain\Enums;

enum ReactionSubjectTypeEnum: string
{
    case CONTENT = 'content';
    case VENUE = 'venue';
    case PLAYER = 'player';
}
