<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Tournament\Application\Services\TournamentAccess;
use App\Modules\Tournament\Domain\Enums\TournamentPermissionEnum;
use App\Modules\Tournament\Domain\Models\TournamentMatch;
use InvalidArgumentException;

final class EventManagementAccess
{
    public function __construct(private readonly TournamentAccess $tournaments) {}

    public function assertAllows(Event $event, Actor $actor, EventResponsibilityPermissionEnum $permission): void
    {
        if (! $this->allows($event, $actor, $permission)) {
            throw new InvalidArgumentException('У вас нет права управлять этим мероприятием.');
        }
    }

    public function allows(Event $event, Actor $actor, EventResponsibilityPermissionEnum $permission): bool
    {
        $isConfirmedSuperadmin = $actor->user_id !== null
            && $actor->user()
                ->where('status', UserStatusEnum::CONFIRMED->value)
                ->where('system_role', UserSystemRoleEnum::SUPERADMIN->value)
                ->exists();

        if ($isConfirmedSuperadmin) {
            return true;
        }

        $isOrganizer = $actor->user_id !== null
            && $event->organizerActor()->where('user_id', $actor->user_id)->exists();

        if ($isOrganizer) {
            return true;
        }

        $tournamentMatch = $event->primary_game_id === null
            ? null
            : TournamentMatch::query()->where('game_id', $event->primary_game_id)->with('tournament')->first();
        if ($tournamentMatch !== null
            && in_array($permission, [
                EventResponsibilityPermissionEnum::UPDATE_MINI_GAME,
                EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_ROSTER,
                EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_SCORE,
                EventResponsibilityPermissionEnum::MANAGE_MINI_GAME_STATISTICS,
                EventResponsibilityPermissionEnum::VIEW_MINI_GAME_AUDIENCE,
                EventResponsibilityPermissionEnum::COMPLETE_MINI_GAME,
            ], true)
            && $this->tournaments->allows($tournamentMatch->tournament, $actor, TournamentPermissionEnum::MANAGE_GAMES)) {
            return true;
        }

        return $actor->user_id !== null
            && $event->participants()
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
        // Game permissions are always evaluated through the owning Event.
    }

    /**
     * A mini-game is an internal part of its parent event and does not have a
     * separate management scope. The parent event owns organizers and
     * responsible participants for the whole aggregate.
     */
    public function managementEvent(Event $event): Event
    {
        return $event;
    }
}
