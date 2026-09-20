<?php

namespace App\Modules\Finance\Domain\Enums;

enum WalletOperationStatusEnum: string
{
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PROCESSING => 'Выполняется',
            self::COMPLETED => 'Завершена',
        };
    }
}
