<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenueModerationMessageDirectionEnum: string
{
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';

    public function label(): string
    {
        return match ($this) {
            self::INCOMING => 'От пользователя',
            self::OUTGOING => 'От модератора',
        };
    }
}
