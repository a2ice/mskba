<?php

namespace App\Modules\Ai\Domain\Enums;

enum PlayerCharacterGenerationStatusEnum: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает запуска',
            self::PROCESSING => 'Генерируется',
            self::COMPLETED => 'Завершена',
            self::FAILED => 'Ошибка',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
