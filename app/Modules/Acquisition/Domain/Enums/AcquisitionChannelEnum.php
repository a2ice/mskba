<?php

namespace App\Modules\Acquisition\Domain\Enums;

enum AcquisitionChannelEnum: string
{
    case QR = 'qr';
    case CONTEXT_ADS = 'context_ads';
    case SOCIAL = 'social';
    case PARTNER = 'partner';
    case REFERRAL = 'referral';
    case DIRECT = 'direct';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::QR => 'QR-код',
            self::CONTEXT_ADS => 'Контекстная реклама',
            self::SOCIAL => 'Социальные сети',
            self::PARTNER => 'Партнёрский канал',
            self::REFERRAL => 'Переход с другого сайта',
            self::DIRECT => 'Прямой переход',
            self::OTHER => 'Другое',
        };
    }
}
