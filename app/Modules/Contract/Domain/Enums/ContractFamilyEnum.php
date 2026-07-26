<?php

namespace App\Modules\Contract\Domain\Enums;

enum ContractFamilyEnum: string
{
    case MEMBERSHIP = 'membership';
    case RELATION = 'relation';

    public function label(): string
    {
        return match ($this) {
            self::MEMBERSHIP => 'Membership contract', // Scope (Organization) -> User
            self::RELATION => 'Relation contract', // Scope -> Scope
        };
    }
}
