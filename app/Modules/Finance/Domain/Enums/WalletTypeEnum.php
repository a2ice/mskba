<?php

namespace App\Modules\Finance\Domain\Enums;

enum WalletTypeEnum: string
{
    case MAIN = 'main';

    public function label(): string
    {
        return match ($this) {
            self::MAIN => 'Основной',
        };
    }
}
