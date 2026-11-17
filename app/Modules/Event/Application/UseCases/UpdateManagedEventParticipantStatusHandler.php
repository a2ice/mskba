<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateManagedEventParticipantStatusHandler
{
    public function __construct(private readonly EventManagementAccess $access) {}

    public function handle(
        string $identifier,
        int $participantId,
        Actor $actor,
        EventParticipantStatusEnum $status,
        bool $asBackoffice = false,
        ?array $participationRoles = null,
    ): Event {
        // Null keeps the legacy status-only API contract. Explicit [] is
        // valid only for an organizer who is NOT attending this event.
        if ($participationRoles !== null && (
            collect($participationRoles)->contains(fn ($role): bool => ! is_string($role)
                || ($case = UserParticipationRoleEnum::tryFrom($role)) === null
                || $case === UserParticipationRoleEnum::ORGANIZER)
            || count($participationRoles) !== count(array_unique($participationRoles))
        )) {
            throw new InvalidArgumentException('Выберите допустимые роли участия.');
        }

        $event = DB::transaction(function () use ($identifier, $participantId, $actor, $status, $asBackoffice, $participationRoles): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            if (! $asBackoffice) {
                $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
            } else {
                $user = $actor->user?->canonical();
                $permissions = app(BackofficePermissionManager::class);
                abort_unless($user !== null
                    && $permissions->allows($user, Permission::BACKOFFICE_ACCESS)
                    && $permissions->allows($user, Permission::BACKOFFICE_EVENT_MANAGE), 403);
            }
            if (in_array($event->status, [EventStatusEnum::DRAFT, EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
                || $event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('Состав этого мероприятия уже нельзя изменять.');
            }

            $participant = $event->participants()->whereKey($participantId)->lockForUpdate()->firstOrFail();
            $isOrganizer = $participant->role === EventParticipantRoleEnum::ORGANIZER;
            if ($isOrganizer) {
                // Management rights stay attached to organizer_actor_id and the
                // organizer participant role; edit only ACTUAL attendance.
                if (! $asBackoffice || ! in_array($status, [
                    EventParticipantStatusEnum::CONFIRMED, EventParticipantStatusEnum::LEFT,
                ], true) || $participationRoles === null
                    || ($status === EventParticipantStatusEnum::CONFIRMED && $participationRoles === [])
                    || ($status === EventParticipantStatusEnum::LEFT && $participationRoles !== [])) {
                    throw new InvalidArgumentException('Для организатора укажите участие и соответствующие роли.');
                }
                if ($participant->user_id !== $event->organizerActor?->user_id) {
                    throw new InvalidArgumentException('Запись организатора не соответствует организатору мероприятия.');
                }
            } elseif ($participationRoles === []) {
                throw new InvalidArgumentException('Выберите хотя бы одну роль участия.');
            }
            $roles = $participationRoles ?? $participant->participation_roles;
            $sameStatus = $participant->status === $status
                && $participant->confirmation_version === $event->participation_confirmation_version;
            if ($sameStatus && ($participationRoles === null || $participationRoles === $participant->participation_roles)) {
                throw new InvalidArgumentException('Статус и роли участника не изменились.');
            }

            // Check capacity only on the transition INTO a confirmed player seat.
            // A confirmed player editing an additional coach/referee role uses
            // the same single seat; a confirmed staff member becoming a player
            // must acquire a free seat. Legacy NULL roles counted as players.
            $hadPlayerSeat = $participant->status === EventParticipantStatusEnum::CONFIRMED
                && $participant->confirmation_version === $event->participation_confirmation_version
                && $participant->hasPlayerRole();
            $willHavePlayerSeat = $status === EventParticipantStatusEnum::CONFIRMED
                && ($roles === null || in_array(UserParticipationRoleEnum::PLAYER->value, $roles, true));
            if ($willHavePlayerSeat && ! $hadPlayerSeat) {
                $confirmedCount = $event->participants()
                    ->occupiesPlayerSpot()
                    ->where('confirmation_version', $event->participation_confirmation_version)
                    ->count();
                if ($event->max_participants !== null && $confirmedCount >= $event->max_participants) {
                    throw new InvalidArgumentException('Все места на мероприятии уже заняты.');
                }
            }

            $participant->forceFill([
                ...($participationRoles === null ? [] : ['participation_roles' => $participationRoles]),
                'status' => $status,
                'joined_at' => $status === EventParticipantStatusEnum::CONFIRMED
                    ? ($participant->status === EventParticipantStatusEnum::CONFIRMED
                        ? ($participant->joined_at ?? now()) : now())
                    : null,
                'left_at' => $status === EventParticipantStatusEnum::LEFT
                    ? ($participant->status === EventParticipantStatusEnum::LEFT
                        ? ($participant->left_at ?? now()) : now())
                    : null,
                'confirmation_version' => $event->participation_confirmation_version,
                ...($participant->status === $status ? [] : [
                    'status_changed_by_actor_id' => $actor->id,
                    'status_changed_at' => now(),
                ]),
                ...($status === EventParticipantStatusEnum::CONFIRMED || $isOrganizer ? [] : [
                    'responsibility_status' => null,
                    'responsibility_requested_by_user_id' => null,
                    'responsibility_requested_at' => null,
                    'responsibility_responded_at' => null,
                ]),
            ])->save();
            if (! $isOrganizer && $status !== EventParticipantStatusEnum::CONFIRMED) {
                $participant->responsibilityPermissions()->delete();
            }

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
