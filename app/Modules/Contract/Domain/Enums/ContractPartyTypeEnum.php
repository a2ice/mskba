<?php

namespace App\Modules\Contract\Domain\Enums;

enum ContractPartyTypeEnum: string
{
    case USER = 'user';
    case TEAM = 'team';
    case COMPANY = 'company';
}
