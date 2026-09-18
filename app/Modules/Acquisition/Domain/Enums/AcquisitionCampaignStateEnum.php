<?php

namespace App\Modules\Acquisition\Domain\Enums;

enum AcquisitionCampaignStateEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case SCHEDULED = 'scheduled';
    case ENDED = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активна',
            self::INACTIVE => 'Выключена',
            self::SCHEDULED => 'Запланирована',
            self::ENDED => 'Завершена',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::ACTIVE => 'Кампания активна',
            self::INACTIVE => 'Кампания сейчас неактивна',
            self::SCHEDULED => 'Кампания ещё не началась',
            self::ENDED => 'Кампания завершена',
        };
    }
}
