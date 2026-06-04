<?php

namespace App\Modules\Notification\Domain\Enums;

enum UserNotificationStatusEnum: string
{
    case NEW = 'new';
    case READ = 'read';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Новое',
            self::READ => 'Прочитанное',
        };
    }
}
