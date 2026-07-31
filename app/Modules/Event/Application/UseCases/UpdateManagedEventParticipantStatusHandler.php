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
    ): Event {
        $event = DB::transaction(function () use ($identifier, $participantId, $actor, $status): Event {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_PARTICIPANTS);
            if (in_array($event->status, [EventStatusEnum::DRAFT, EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)) {
                throw new InvalidArgumentException('Состав этого мероприятия уже нельзя изменять.');
            }

            $participant = $event->participants()->whereKey($participantId)->lockForUpdate()->firstOrFail();
            if ($participant->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Статус организатора этим действием не изменяется.');
            }
            if ($participant->status === $status
                && $participant->confirmation_version === $event->participation_confirmation_version) {
                throw new InvalidArgumentException('У пользователя уже установлен этот статус.');
            }

            if ($status === EventParticipantStatusEnum::CONFIRMED) {
                $confirmedCount = $event->participants()
                    ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                    ->where('confirmation_version', $event->participation_confirmation_version)
                    ->count();
                if ($event->max_participants !== null && $confirmedCount >= $event->max_participants) {
                    throw new InvalidArgumentException('Все места на мероприятии уже заняты.');
                }
            }

            $participant->forceFill([
                'status' => $status,
                'joined_at' => $status === EventParticipantStatusEnum::CONFIRMED ? now() : null,
                'left_at' => $status === EventParticipantStatusEnum::LEFT ? now() : null,
                'confirmation_version' => $event->participation_confirmation_version,
                'status_changed_by_actor_id' => $actor->id,
                'status_changed_at' => now(),
                ...($status === EventParticipantStatusEnum::CONFIRMED ? [] : [
                    'responsibility_status' => null,
                    'responsibility_requested_by_user_id' => null,
                    'responsibility_requested_at' => null,
                    'responsibility_responded_at' => null,
                ]),
            ])->save();
            if ($status !== EventParticipantStatusEnum::CONFIRMED) {
                $participant->responsibilityPermissions()->delete();
            }

            return $event;
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
