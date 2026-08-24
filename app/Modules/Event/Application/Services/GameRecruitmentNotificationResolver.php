<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;

final class GameRecruitmentNotificationResolver
{
    public function resolve(GameAdmission $admission): int
    {
        $notifications = UserNotification::query()
            ->where('status', UserNotificationStatusEnum::NEW->value)
            ->where('payload->source', 'game.recruitment')
            ->where('payload->game_admission_id', $admission->id)
            ->get();

        foreach ($notifications as $notification) {
            $notification->markAsRead();
        }

        return $notifications->count();
    }
}
