<?php

namespace App\Modules\Finance\Domain\Enums;

enum WalletBalanceTypeEnum: string
{
    case REAL = 'real';
    case BONUS = 'bonus';

    public function label(): string
    {
        return match ($this) {
            self::REAL => 'Реальные средства',
            self::BONUS => 'Бонусные средства',
        };
    }
}
