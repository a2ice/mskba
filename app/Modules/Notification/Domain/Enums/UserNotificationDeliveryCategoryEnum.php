<?php

namespace App\Modules\Notification\Domain\Enums;

enum UserNotificationDeliveryCategoryEnum: string
{
    case SYSTEM = 'system';
    case REQUEST = 'request';
    case GENERAL = 'general';
}
