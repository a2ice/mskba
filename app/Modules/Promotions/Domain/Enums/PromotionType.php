<?php

namespace App\Modules\Promotions\Domain\Enums;

enum PromotionType: string
{
    case REWARDABLE = 'rewardable';
    case MULTI_REWARDABLE = 'multi_rewardable';
    case SIMPLE = 'simple';

    public function label(): string
    {
        return match ($this) {
            self::REWARDABLE => 'С вознаграждением',
            self::MULTI_REWARDABLE => 'Несколько вознаграждений (пока недоступно)',
            self::SIMPLE => 'Информационная акция',
        };
    }
}
