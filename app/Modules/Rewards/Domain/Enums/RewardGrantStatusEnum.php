<?php

namespace App\Modules\Rewards\Domain\Enums;

enum RewardGrantStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
}
