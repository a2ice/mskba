<?php

namespace App\Modules\Finance\Domain\Enums;

enum WalletSpendingPolicyEnum: string
{
    case BONUS_THEN_REAL = 'bonus_then_real';
    case REAL_ONLY = 'real_only';
    case BONUS_ONLY = 'bonus_only';

    public function label(): string
    {
        return match ($this) {
            self::BONUS_THEN_REAL => 'Сначала бонусные, затем реальные',
            self::REAL_ONLY => 'Только реальные',
            self::BONUS_ONLY => 'Только бонусные',
        };
    }
}
