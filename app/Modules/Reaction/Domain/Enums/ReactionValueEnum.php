<?php

namespace App\Modules\Reaction\Domain\Enums;

enum ReactionValueEnum: int
{
    case LIKE = 1;
    case DISLIKE = -1;
}
