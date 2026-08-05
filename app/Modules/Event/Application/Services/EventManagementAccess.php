<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use InvalidArgumentException;

final class EventManagementAccess
{
    public function assertAllows(Event $event, Actor $actor, EventResponsibilityPermissionEnum $permission): void
    {
        if (! $this->allows($event, $actor, $permission)) {
            throw new InvalidArgumentException('У вас нет права управлять этим мероприятием.');
        }
    }

    public function allows(Event $event, Actor $actor, EventResponsibilityPermissionEnum $permission): bool
    {
        $managementEvent = $this->managementEvent($event);
        $isOrganizer = $actor->user_id !== null
            && $managementEvent->organizerActor()->where('user_id', $actor->user_id)->exists();

        if ($isOrganizer) {
            return true;
        }

        return $actor->user_id !== null
            && $managementEvent->participants()
                ->where('user_id', $actor->user_id)
                ->where('responsibility_status', EventResponsibilityStatusEnum::ACCEPTED->value)
                ->whereHas('responsibilityPermissions', fn ($query) => $query
                    ->where('permission', $permission->value))
                ->exists();
    }

    public function canManage(Event $event, Actor $actor): bool
    {
        foreach (EventResponsibilityPermissionEnum::cases() as $permission) {
            if ($this->allows($event, $actor, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<EventResponsibilityPermissionEnum> */
    public function effectivePermissions(Event $event, Actor $actor): array
    {
        return array_values(array_filter(
            EventResponsibilityPermissionEnum::cases(),
            fn (EventResponsibilityPermissionEnum $permission): bool => $this->allows($event, $actor, $permission),
        ));
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
