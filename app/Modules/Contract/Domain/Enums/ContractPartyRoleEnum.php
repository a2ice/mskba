<?php

namespace App\Modules\Contract\Domain\Enums;

enum ContractPartyRoleEnum: string
{
    case HOLDER = 'holder';
    case PROVIDER = 'provider';
    case CUSTOMER = 'customer';
}
