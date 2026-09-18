<?php

namespace App\Modules\Acquisition\Domain\Enums;

enum AcquisitionCampaignStateEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case SCHEDULED = 'scheduled';
    case ENDED = 'ended';

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
