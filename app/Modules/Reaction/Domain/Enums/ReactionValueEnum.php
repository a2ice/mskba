<?php

namespace App\Modules\Reaction\Domain\Enums;

enum ReactionValueEnum: int
{
    case NONE = 0;
    case LIKE = 1;
    case DISLIKE = -1;
}
