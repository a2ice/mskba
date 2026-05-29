<?php

namespace App\Modules\Contract\Domain\Enums;

enum ContractSubjectTypeEnum: string
{
    case VENUE = 'venue';
    case EVENT = 'event';
}
