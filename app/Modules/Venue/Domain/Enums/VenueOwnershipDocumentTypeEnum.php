<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueOwnershipDocumentTypeEnum: string
{
    case OWNERSHIP_DOCUMENT = 'ownership_document';
    case LEASE_OR_USE_AGREEMENT = 'lease_or_use_agreement';
    case MANAGEMENT_AGREEMENT = 'management_agreement';
    case POWER_OF_ATTORNEY = 'power_of_attorney';
    case OFFICIAL_LETTER = 'official_letter';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OWNERSHIP_DOCUMENT => 'Документ о собственности',
            self::LEASE_OR_USE_AGREEMENT => 'Договор аренды / пользования',
            self::MANAGEMENT_AGREEMENT => 'Договор управления',
            self::POWER_OF_ATTORNEY => 'Доверенность',
            self::OFFICIAL_LETTER => 'Официальное письмо',
            self::OTHER => 'Другое основание',
        };
    }
}
