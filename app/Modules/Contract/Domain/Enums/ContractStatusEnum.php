<?php

namespace App\Modules\Contract\Domain\Enums;

enum ContractStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
