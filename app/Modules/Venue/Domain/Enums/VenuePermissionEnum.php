<?php

namespace App\Modules\Venue\Domain\Enums;

enum VenuePermissionEnum: string
{
    case VIEW = 'view';
    case EDIT = 'edit';
    case EDIT_SCHEDULE = 'edit.schedule';

    case REMOVE = 'remove';

    public function label(): string
    {
        return match ($this) {
            self::VIEW => 'Просмотр',
            self::EDIT => 'Редактирование',
            self::EDIT_SCHEDULE => 'Редактирование расписания',

            self::REMOVE => 'Удаление',
        };
    }
}
