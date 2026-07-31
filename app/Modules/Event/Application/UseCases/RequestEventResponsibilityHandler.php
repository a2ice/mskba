<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Application\Services\EventManagementAccess;
use App\Modules\Event\Application\Services\EventResponsibilityPermissionManager;
use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RequestEventResponsibilityHandler
{
    public function __construct(
        private readonly EventManagementAccess $access,
        private readonly EventResponsibilityPermissionManager $permissions,
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    /** @param list<string> $permissionValues */
    public function handle(string $identifier, int $participantId, Actor $actor, array $permissionValues): Event
    {
        [$event, $participant] = DB::transaction(function () use ($identifier, $participantId, $actor, $permissionValues): array {
            $event = Event::query()->whereRouteIdentifier($identifier)->lockForUpdate()->firstOrFail();
            $this->access->assertAllows($event, $actor, EventResponsibilityPermissionEnum::MANAGE_RESPONSIBILITIES);
            $this->access->assertOwnsManagementScope($event);
            $this->assertEventIsActive($event);
            $participant = $event->participants()->whereKey($participantId)->lockForUpdate()->firstOrFail();

            if ($participant->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Организатор уже отвечает за мероприятие.');
            }

            if ($participant->status !== EventParticipantStatusEnum::CONFIRMED
                || $participant->confirmation_version !== $event->participation_confirmation_version) {
                throw new InvalidArgumentException('Ответственным можно назначить только подтверждённого участника.');
            }

            if ($participant->responsibility_status === EventResponsibilityStatusEnum::ACCEPTED) {
                throw new InvalidArgumentException('Участник уже является ответственным.');
            }

            if ($participant->responsibility_status === EventResponsibilityStatusEnum::PENDING) {
                throw new InvalidArgumentException('Участник уже получил запрос на назначение.');
            }

            $participant->forceFill([
                'responsibility_status' => EventResponsibilityStatusEnum::PENDING,
                'responsibility_requested_by_user_id' => $actor->user_id,
                'responsibility_requested_at' => now(),
                'responsibility_responded_at' => null,
            ])->save();
            $this->permissions->replace($event, $participant, $actor, $permissionValues);

            return [$event, $participant->load('user')];
        });

        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $participant->user_id,
            type: UserNotificationTypeEnum::REMINDER,
            title: 'Назначение ответственным',
            body: "Вас приглашают стать ответственным за мероприятие «{$event->title}».",
            actionUrl: route('events.show', $event->routeIdentifier()),
            actionText: 'Ответить',
            payload: ['event_id' => $event->id, 'participant_id' => $participant->id],
        ));
        event(new EventChanged($event->id));

        return $event;
    }

    private function assertEventIsActive(Event $event): void
    {
        if (in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true)
            || $event->starts_at->lessThanOrEqualTo(now())) {
            throw new InvalidArgumentException('Для этого мероприятия назначение уже недоступно.');
        }
    }
}
