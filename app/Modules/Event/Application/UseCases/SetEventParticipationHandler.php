<?php

namespace App\Modules\Event\Application\UseCases;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SetEventParticipationHandler
{
    public function handle(
        string $identifier,
        User $user,
        EventParticipantStatusEnum $status,
    ): Event {
        $event = DB::transaction(function () use ($identifier, $user, $status): Event {
            $event = Event::query()
                ->whereRouteIdentifier($identifier)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->status !== EventStatusEnum::PUBLISHED || $event->visibility !== EventVisibilityEnum::PUBLIC) {
                throw new InvalidArgumentException('Для этого мероприятия сейчас нельзя изменить участие.');
            }

            if ($event->ends_at->lessThanOrEqualTo(now())) {
                throw new InvalidArgumentException('После завершения мероприятия изменить участие нельзя.');
            }

            $participant = $event->participants()->where('user_id', $user->id)->first();

            if ($participant?->role === EventParticipantRoleEnum::ORGANIZER) {
                throw new InvalidArgumentException('Организатор не может изменить участие в собственном мероприятии.');
            }

            if ($status === EventParticipantStatusEnum::CONFIRMED
                && $participant?->status !== EventParticipantStatusEnum::CONFIRMED) {
                $confirmedCount = $event->participants()
                    ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                    ->where('confirmation_version', $event->participation_confirmation_version)
                    ->count();

                if ($event->max_participants !== null && $confirmedCount >= $event->max_participants) {
                    throw new InvalidArgumentException('Все места на мероприятии уже заняты.');
                }
            }

            if ($status !== EventParticipantStatusEnum::CONFIRMED) {
                $participant?->responsibilityPermissions()->delete();
            }

            $event->participants()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => EventParticipantRoleEnum::PARTICIPANT,
                    'status' => $status,
                    'joined_at' => $status === EventParticipantStatusEnum::CONFIRMED ? now() : null,
                    'left_at' => $status === EventParticipantStatusEnum::LEFT ? now() : null,
                    'confirmation_version' => $event->participation_confirmation_version,
                    'status_changed_by_actor_id' => null,
                    'status_changed_at' => null,
                    ...($status === EventParticipantStatusEnum::CONFIRMED ? [] : [
                        'responsibility_status' => null,
                        'responsibility_requested_by_user_id' => null,
                        'responsibility_requested_at' => null,
                        'responsibility_responded_at' => null,
                    ]),
                ],
            );

            return $event->load('participants.user');
        });

        event(new EventChanged($event->id));

        return $event;
    }
}
