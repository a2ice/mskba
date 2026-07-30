<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use InvalidArgumentException;

final class EventManagementAccess
{
    public function assertCanManage(Event $event, Actor $actor): void
    {
        if (! $this->canManage($event, $actor)) {
            throw new InvalidArgumentException('У вас нет права управлять этим мероприятием.');
        }
    }

    public function canManage(Event $event, Actor $actor): bool
    {
        $isOrganizer = $actor->user_id !== null
            && $event->organizerActor()->where('user_id', $actor->user_id)->exists();
        $isResponsible = $actor->user_id !== null
            && $event->participants()
                ->where('user_id', $actor->user_id)
                ->where('responsibility_status', EventResponsibilityStatusEnum::ACCEPTED->value)
                ->exists();

        return $isOrganizer || $isResponsible
            || ($actor->user?->isConfirmed() === true
                && $actor->user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN));
    }
}
