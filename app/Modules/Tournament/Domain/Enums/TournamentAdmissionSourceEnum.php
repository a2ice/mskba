<?php

namespace App\Modules\Tournament\Domain\Enums;

enum TournamentAdmissionSourceEnum: string
{
    case STANDARD = 'standard';
    case ON_SITE = 'on_site';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Обычная заявка',
            self::ON_SITE => 'Регистрация на месте',
        };
    }
}
