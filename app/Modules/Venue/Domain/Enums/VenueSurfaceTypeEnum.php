<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueSurfaceTypeEnum: string
{
    case PARQUET = 'parquet';
    case SPORTS_PVC = 'sports_pvc';
    case RUBBER = 'rubber';
    case RUBBER_CRUMB = 'rubber_crumb';
    case ASPHALT = 'asphalt';
    case CONCRETE = 'concrete';
    case ACRYLIC = 'acrylic';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PARQUET => 'Паркет / спортивное дерево',
            self::SPORTS_PVC => 'Спортивный ПВХ / линолеум',
            self::RUBBER => 'Резиновое покрытие',
            self::RUBBER_CRUMB => 'Резиновое покрытие с крошкой',
            self::ASPHALT => 'Асфальт',
            self::CONCRETE => 'Бетон',
            self::ACRYLIC => 'Акриловое покрытие / hard court',
            self::OTHER => 'Другое',
        };
    }
}
