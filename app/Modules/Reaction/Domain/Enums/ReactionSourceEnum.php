<?php

namespace App\Modules\Reaction\Domain\Enums;

enum ReactionSourceEnum: string
{
    case WEB = 'web';
    case TELEGRAM = 'telegram';
}
