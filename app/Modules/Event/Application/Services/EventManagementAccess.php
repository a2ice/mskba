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
        $managementEvent = $this->managementEvent($event);
        $isOrganizer = $actor->user_id !== null
            && $managementEvent->organizerActor()->where('user_id', $actor->user_id)->exists();
        $isResponsible = $actor->user_id !== null
            && $managementEvent->participants()
                ->where('user_id', $actor->user_id)
                ->where('responsibility_status', EventResponsibilityStatusEnum::ACCEPTED->value)
                ->exists();

        return $isOrganizer || $isResponsible
            || ($actor->user?->isConfirmed() === true
                && $actor->user->hasSystemRole(UserSystemRoleEnum::SUPERADMIN));
    }

    public function assertOwnsManagementScope(Event $event): void
    {
        if ($event->parent_event_id !== null) {
            throw new InvalidArgumentException(
                'Ответственных назначают на основном мероприятии, а не на отдельной мини-игре.',
            );
        }
    }

    /**
     * A mini-game is an internal part of its parent event and does not have a
     * separate management scope. The parent event owns organizers and
     * responsible participants for the whole aggregate.
     */
    public function managementEvent(Event $event): Event
    {
        if ($event->parent_event_id === null) {
            return $event;
        }

        return $event->parentEvent()->firstOrFail();
    }
}
